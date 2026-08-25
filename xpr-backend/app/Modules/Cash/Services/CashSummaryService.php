<?php

declare(strict_types=1);

namespace App\Modules\Cash\Services;

use App\Modules\Cash\Models\CashMovement;
use App\Modules\Cash\Resources\CashMovementResource;
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
 * ── La fusion en lecture a été REMPLACÉE par un miroir (2026-08-25) ───────
 *
 * Ce service lisait `payments` et les fusionnait aux mouvements à l'affichage.
 * Sur demande expresse de l'exploitant, chaque règlement écrit désormais sa
 * propre ligne dans `cash_movements` (cf. `PaymentCashMirror`).
 *
 * Cette lecture NE DOIT PLUS interroger `payments`, et c'est le point le plus
 * important de ce fichier : les miroirs sont déjà dans `cash_movements`, les
 * relire ailleurs compterait chaque règlement DEUX FOIS — 200 MAD affichés pour
 * 100 encaissés, sur les trois cartes comme dans le journal. Le service ne
 * connaît donc plus qu'une seule table.
 *
 * Ce que le changement coûte, dit ici parce que c'est ici qu'on viendra
 * chercher : la caisse ne peut plus se contenter de relire la source, elle
 * dépend d'une copie tenue à jour. Toute écriture de règlement qui
 * contournerait `PaymentWriteService` laisserait le journal en retard, sans que
 * rien ne le signale. `xpr:backfill-cash-mirror` répare cet écart.
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
        // Le TIERS est chargé d'emblée, et la FACTURE avec lui : l'écran
        // affiche le nom du client sur chaque ligne et le numéro de la pièce
        // sur celles qui viennent d'un règlement. Les lire ligne à ligne ferait
        // une requête par mouvement du journal.
        $movements = CashMovement::query()
            ->with(['partner', 'payment.invoice'])
            ->whereBetween('occurred_at', [$from->toDateString(), $to->toDateString()])
            ->get();

        // UNE SEULE TABLE, et c'est la garde principale de ce service : les
        // règlements sont déjà dans `cash_movements` sous forme de miroirs
        // (`payment_id` non nul). Rouvrir une requête sur `payments` ici
        // compterait chaque encaissement deux fois.
        //
        // Un règlement est toujours positif (`payments_amount_positive_check`),
        // son miroir l'est donc aussi : il tombe du bon côté sans traitement
        // particulier.
        $inflowCents = (int) $movements->where('amount_cents', '>', 0)->sum('amount_cents');
        $outflowCents = (int) abs((int) $movements->where('amount_cents', '<', 0)->sum('amount_cents'));
        $balanceCents = (int) $movements->sum('amount_cents');

        $listed = $this->listFor($movements, $direction);

        return [
            'balanceCents' => $balanceCents,
            'inflowCents' => $inflowCents,
            'outflowCents' => $outflowCents,
            'currency' => $this->resolveCurrency($movements),
            'movements' => $listed,
        ];
    }

    /**
     * Le journal filtré par sens, du plus récent au plus ancien.
     *
     * UNE seule source depuis le miroir (2026-08-25) : les règlements sont dans
     * `cash_movements` comme les écritures saisies, il n'y a donc plus deux
     * collections à fusionner ni deux formes de ligne à composer.
     *
     * Le tri porte sur les MODÈLES et non sur les lignes sérialisées : la
     * seconde clé est `created_at`, qui ne figure pas dans la sortie et
     * départage deux écritures du même jour — sans elle, l'ordre de deux
     * encaissements datés du 16 dépendrait de l'ordre de retour de PostgreSQL,
     * qui n'en garantit aucun.
     *
     * @param  EloquentCollection<int, CashMovement>  $movements
     * @param  'inflow'|'outflow'|null  $direction
     * @return list<array<string, mixed>>
     */
    private function listFor(EloquentCollection $movements, ?string $direction): array
    {
        $listed = match ($direction) {
            'inflow' => $movements->where('amount_cents', '>', 0),
            'outflow' => $movements->where('amount_cents', '<', 0),
            default => $movements,
        };

        /** @var list<array<string, mixed>> $rows */
        $rows = (new Collection($listed->all()))
            ->sortByDesc(fn (Model $entry): string => $this->sortKey($entry))
            ->map(fn (Model $entry): array => CashMovementResource::make($entry)->resolve())
            ->values()
            ->all();

        return $rows;
    }

    /**
     * Clé de tri chronologique.
     *
     * Une chaîne et non un tableau : `occurred_at` est un jour sans heure, et
     * le `created_at` accolé — à la microseconde — départage les écritures d'un
     * même jour dans leur ordre de saisie.
     */
    private function sortKey(Model $entry): string
    {
        $valueDate = $entry->getAttribute('occurred_at');
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
     */
    private function resolveCurrency(EloquentCollection $movements): string
    {
        $first = $movements->first();

        return $first instanceof CashMovement ? $first->currency : 'MAD';
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
