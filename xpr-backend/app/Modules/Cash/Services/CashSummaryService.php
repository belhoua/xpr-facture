<?php

declare(strict_types=1);

namespace App\Modules\Cash\Services;

use App\Modules\Cash\Models\CashMovement;
use App\Modules\Cash\Resources\CashMovementResource;
use App\Modules\Cash\Resources\InvoicePaymentEntryResource;
use App\Modules\Payments\Models\Payment;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Journal de caisse d'une période, et ses trois cumuls.
 *
 * ── Le journal a DEUX sources ─────────────────────────────────────────────
 *
 * `cash_movements` porte ce qu'on saisit à la main : un encaissement au
 * comptoir, un achat de fournitures. `payments` porte les règlements reçus sur
 * les factures. Les deux sont des mouvements de trésorerie ; les tenir séparés
 * donnait une caisse à 0,00 MAD le jour où 7 000 MAD avaient bel et bien été
 * encaissés sur une facture — l'écran ne mentait pas sur sa table, il mentait
 * sur la trésorerie, qui est la question posée.
 *
 * La fusion se fait ICI, EN LECTURE, et pas en dupliquant chaque règlement dans
 * `cash_movements` à l'écriture. La raison tient en une phrase : un règlement
 * est déjà écrit quelque part, et deux copies d'un même fait divergent toujours
 * — au retrait d'un règlement, à la correction d'un montant, ou le jour où
 * quelqu'un supprime la copie depuis l'écran Caisses sans que la facture en
 * sache rien. Ici, la caisse LIT les règlements : elle ne peut pas les
 * contredire, et les règlements déjà en base apparaissent sans reprise de
 * données.
 *
 * ── Les cumuls portent TOUJOURS sur la période entière ─────────────────────
 *
 * `$direction` ne filtre que la LISTE, jamais les agrégats. C'est la seule
 * lecture cohérente : un écran qui n'affiche que les encaissements peut
 * légitimement vouloir le total encaissé, mais laisser le filtre d'affichage
 * amputer `balanceCents` produirait un « solde » égal aux seuls
 * encaissements — un chiffre faux, et faux sans le dire.
 *
 * `inflowCents` répond donc déjà à « total des encaissements sur la
 * période », que le filtre soit posé ou non — règlements de factures compris.
 */
final class CashSummaryService
{
    /**
     * @param  'inflow'|'outflow'|null  $direction  null = tout le journal
     * @return array{
     *     balanceCents: int,
     *     inflowCents: int,
     *     outflowCents: int,
     *     currency: string,
     *     movements: list<array<string, mixed>>
     * }
     */
    public function summarize(string $period, ?string $direction = null): array
    {
        [$from, $to] = $this->resolvePeriod($period);

        // Le TIERS est chargé d'emblée : l'écran affiche son nom sur chaque
        // ligne, et le lire mouvement par mouvement ferait une requête par
        // ligne du journal.
        $movements = CashMovement::query()
            ->with('partner')
            ->whereBetween('occurred_at', [$from->toDateString(), $to->toDateString()])
            ->get();

        // Même raison pour la FACTURE : c'est elle qui porte le nom du client
        // figé à l'émission et le numéro affiché sur la ligne. Le scope tenant
        // s'applique aux deux modèles (`BelongsToCompany`), et le soft delete
        // de `Payment` écarte d'office les règlements retirés — un chèque
        // revenu impayé ne doit plus compter dans les encaissements.
        $payments = Payment::query()
            ->with('invoice')
            ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
            ->get();

        // Un règlement est toujours positif (`payments_amount_positive_check`),
        // donc toujours un encaissement : il entre dans `inflowCents` sans
        // condition de signe, et jamais dans `outflowCents`.
        $paymentsTotal = (int) $payments->sum('amount_cents');

        $inflowCents = (int) $movements->where('amount_cents', '>', 0)->sum('amount_cents') + $paymentsTotal;
        $outflowCents = (int) abs((int) $movements->where('amount_cents', '<', 0)->sum('amount_cents'));
        $balanceCents = (int) $movements->sum('amount_cents') + $paymentsTotal;

        $listed = $this->listFor($movements, $payments, $direction);

        return [
            'balanceCents' => $balanceCents,
            'inflowCents' => $inflowCents,
            'outflowCents' => $outflowCents,
            'currency' => $this->resolveCurrency($movements, $payments),
            'movements' => $listed,
        ];
    }

    /**
     * Les deux sources fusionnées, filtrées par sens, du plus récent au plus
     * ancien.
     *
     * Le tri porte sur les MODÈLES et non sur les lignes sérialisées : la
     * seconde clé est `created_at`, qui ne figure pas dans la sortie et
     * départage deux écritures du même jour — sans elle, l'ordre de deux
     * encaissements datés du 16 dépendrait de l'ordre de retour de PostgreSQL,
     * qui n'en garantit aucun.
     *
     * @param  EloquentCollection<int, CashMovement>  $movements
     * @param  EloquentCollection<int, Payment>  $payments
     * @param  'inflow'|'outflow'|null  $direction
     * @return list<array<string, mixed>>
     */
    private function listFor(
        EloquentCollection $movements,
        EloquentCollection $payments,
        ?string $direction,
    ): array {
        $listedMovements = match ($direction) {
            'inflow' => $movements->where('amount_cents', '>', 0),
            'outflow' => $movements->where('amount_cents', '<', 0),
            default => $movements,
        };

        // Aucun règlement n'est un décaissement : le filtre « sorties » n'en
        // rend aucun, plutôt que d'en rendre au signe inversé.
        $listedPayments = $direction === 'outflow' ? new EloquentCollection : $payments;

        /** @var Collection<int, Model> $entries */
        $entries = (new Collection($listedMovements->all()))
            ->merge($listedPayments->all())
            ->sortByDesc(fn (Model $entry): string => $this->sortKey($entry))
            ->values();

        /** @var list<array<string, mixed>> $rows */
        $rows = $entries
            ->map(fn (Model $entry): array => $entry instanceof Payment
                ? InvoicePaymentEntryResource::make($entry)->resolve()
                : CashMovementResource::make($entry)->resolve())
            ->values()
            ->all();

        return $rows;
    }

    /**
     * Clé de tri chronologique, comparable entre les deux sources.
     *
     * Une chaîne et non un tableau : la date de valeur (`occurred_at` /
     * `paid_on`) est un jour sans heure des deux côtés, et le `created_at`
     * accolé — à la microseconde — départage les écritures d'un même jour dans
     * leur ordre de saisie.
     */
    private function sortKey(Model $entry): string
    {
        $valueDate = $entry instanceof Payment ? $entry->paid_on : $entry->getAttribute('occurred_at');
        $createdAt = $entry->getAttribute('created_at');

        return ($valueDate instanceof Carbon ? $valueDate->toDateString() : '')
            .'|'
            .($createdAt instanceof Carbon ? $createdAt->format('Y-m-d H:i:s.u') : '');
    }

    /**
     * Devise du journal.
     *
     * Prise sur la première écriture rencontrée, mouvements d'abord : le
     * multi-devises est prévu au schéma (§7) mais aucun écran ne l'expose
     * encore, et une société n'en pratique qu'une en caisse. Repli sur MAD
     * quand la période est vide — l'écran doit afficher « 0,00 MAD », pas
     * « 0,00 ».
     *
     * @param  EloquentCollection<int, CashMovement>  $movements
     * @param  EloquentCollection<int, Payment>  $payments
     */
    private function resolveCurrency(EloquentCollection $movements, EloquentCollection $payments): string
    {
        $first = $movements->first();

        if ($first instanceof CashMovement) {
            return $first->currency;
        }

        $firstPayment = $payments->first();

        return $firstPayment instanceof Payment ? $firstPayment->currency : 'MAD';
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolvePeriod(string $period): array
    {
        $to = Carbon::today();

        return match ($period) {
            'last7' => [$to->copy()->subDays(6), $to],
            'last90' => [$to->copy()->subDays(89), $to],
            'year' => [$to->copy()->startOfYear(), $to],
            default => [$to->copy()->subDays(29), $to],
        };
    }
}
