<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Cash\Models\CashMovement;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\Partners\Enums\PartnerType;
use App\Modules\Partners\Models\Partner;
use App\Modules\Payments\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class DashboardStatsService
{
    /**
     * @return array{
     *     currency: string,
     *     revenueCents: int,
     *     revenueTrend: float,
     *     collectedCents: int,
     *     outstandingCents: int,
     *     overdueCents: int,
     *     overdueCount: int,
     *     revenueSeries: list<array{month: string, invoicedCents: int, collectedCents: int}>,
     *     statusBreakdown: list<array{status: string, count: int, totalCents: int}>,
     *     activeClients: int,
     *     activeSuppliers: int,
     *     cashBalanceCents: int,
     *     cashInflowCents: int,
     *     cashOutflowCents: int,
     *     topClients: list<array{name: string, partnerId: ?string, totalCents: int, invoiceCount: int}>
     * }
     */
    public function forPeriod(string $period): array
    {
        [$from, $to] = $this->resolvePeriod($period);
        [$previousFrom, $previousTo] = $this->previousPeriod($from, $to);

        $invoices = Document::query()->ofType(DocumentType::Invoice)
            ->where('status', '!=', 'cancelled')
            // Les SEULES colonnes que les six agrégats ci-dessous consultent.
            // Sans cette liste, chaque facture était hydratée en entier —
            // `notes`, `terms`, les adresses figées — pour ne fournir qu'un
            // montant et un statut. Sur un exercice complet, c'est la
            // différence entre quelques centaines de kilo-octets et plusieurs
            // mégaoctets rapatriés puis castés à chaque ouverture du tableau
            // de bord.
            ->select([
                'id',
                'status',
                'total_cents',
                'currency',
                'issued_at',
                'created_at',
                'partner_id',
                'client_name',
            ])
            ->where(function ($query) use ($from, $to): void {
                $query
                    ->whereBetween('issued_at', [$from->toDateString(), $to->toDateString()])
                    ->orWhere(function ($drafts) use ($to): void {
                        $drafts
                            ->where('status', 'draft')
                            ->whereDate('created_at', '<=', $to->toDateString());
                    });
            })
            ->get();

        $revenueCents = (int) $invoices->sum('total_cents');

        // La période PRÉCÉDENTE ne sert qu'à une chose : le pourcentage
        // d'évolution. Elle était rapatriée ligne à ligne pour n'en tirer
        // qu'une somme — un `SUM()` la donne en base, sans hydrater un seul
        // modèle. Le résultat est identique par construction.
        $previousRevenueCents = (int) Document::query()->ofType(DocumentType::Invoice)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('issued_at', [$previousFrom->toDateString(), $previousTo->toDateString()])
            ->sum('total_cents');

        // ── ENCAISSÉ et RESTANT DÛ : des RÈGLEMENTS, pas des factures ──────
        //
        // Les deux sommaient le `total_cents` des factures selon leur STATUT
        // jusqu'au 2026-08-26, ce qui donnait deux chiffres faux :
        //
        //  - « encaissé » retenait le total des factures payées OU
        //    PARTIELLEMENT payées. Une facture de 240 MAD réglée à 140
        //    affichait donc 240 encaissés — la carte annonçait de la
        //    trésorerie qui n'était pas rentrée ;
        //  - « restant dû » retenait, symétriquement, le total des factures
        //    non soldées : 240 MAD dus sur une facture dont 140 avaient déjà
        //    été payés.
        //
        // Les deux se lisent maintenant sur les RÈGLEMENTS RÉELLEMENT
        // ENREGISTRÉS, ventilés par facture.
        $collectedByInvoice = $this->collectedByInvoice($invoices);
        $collectedCents = (int) $collectedByInvoice->sum();

        // Le reste à payer est calculé PAR FACTURE puis additionné, et non par
        // une soustraction globale « CA − encaissé ». Deux raisons :
        //
        //  1. les BROUILLONS entrent dans le chiffre d'affaires (la requête
        //     ci-dessus les retient) mais ne sont dus par personne : ils sont
        //     écartés ici. Sur un portefeuille qui en compte, les trois cartes
        //     ne s'additionnent donc plus à la main — c'est voulu, un brouillon
        //     n'est pas une créance ;
        //  2. `max(0, …)` par ligne : une facture surpayée — trop-perçu, avoir
        //     à établir — ne doit pas venir effacer le dû d'une autre.
        $outstandingCents = (int) $invoices
            ->reject(fn (Document $invoice): bool => $invoice->status === DocumentStatus::Draft)
            ->sum(fn (Document $invoice): int => max(
                0,
                $invoice->total_cents - (int) $collectedByInvoice->get($invoice->id, 0),
            ));
        $overdueRows = $invoices->where('status', DocumentStatus::Overdue);
        $overdueCents = (int) $overdueRows->sum('total_cents');
        $overdueCount = $overdueRows->count();

        // Voir CashSummaryService : `first()` est nul sur une période vide,
        // Larastan le type comme non-nul.
        $firstInvoice = $invoices->first();

        return [
            'currency' => $firstInvoice instanceof Document ? $firstInvoice->currency : 'MAD',
            'revenueCents' => $revenueCents,
            'revenueTrend' => $this->trend($revenueCents, $previousRevenueCents),
            'collectedCents' => $collectedCents,
            'outstandingCents' => $outstandingCents,
            'overdueCents' => $overdueCents,
            'overdueCount' => $overdueCount,
            'revenueSeries' => $this->revenueSeries($invoices, $from, $to, $collectedByInvoice),
            'statusBreakdown' => $this->statusBreakdown($invoices),
            // Le répertoire n'est PAS borné à la période : « clients actifs »
            // désigne l'état courant du portefeuille, pas ceux qui ont facturé
            // sur les 30 derniers jours.
            'activeClients' => $this->countActive(PartnerType::Client),
            'activeSuppliers' => $this->countActive(PartnerType::Supplier),
            ...$this->cashFlow($from, $to),
            'topClients' => $this->topClients($invoices),
        ];
    }

    /**
     * Tiers actifs par rôle commercial. `ofType` fait remonter les fiches
     * `both` dans les deux comptes : un tiers à la fois client et fournisseur
     * est bien l'un ET l'autre, le compter une seule fois fausserait les deux.
     */
    private function countActive(PartnerType $type): int
    {
        return Partner::query()
            ->ofType($type)
            ->where('is_active', true)
            ->count();
    }

    /**
     * Trésorerie de la période. Mêmes bornes que le chiffre d'affaires pour que
     * les deux cartes du dashboard parlent du même intervalle.
     *
     * @return array{cashBalanceCents: int, cashInflowCents: int, cashOutflowCents: int}
     */
    private function cashFlow(Carbon $from, Carbon $to): array
    {
        // Les trois cumuls sont calculés EN BASE, en une passe. Ils l'étaient
        // en PHP sur la collection entière des mouvements de la période : trois
        // parcours d'une collection hydratée pour trois entiers. Le
        // `FILTER (WHERE …)` de PostgreSQL fait les trois d'un coup, et rien ne
        // remonte à part la ligne de résultat.
        //
        // `toBase()` court-circuite l'hydratation Eloquent — le scope tenant,
        // lui, a déjà été appliqué au Builder par le trait BelongsToCompany.
        /** @var object{balance: int|string|null, inflow: int|string|null, outflow: int|string|null} $row */
        $row = CashMovement::query()
            ->whereBetween('occurred_at', [$from->toDateString(), $to->toDateString()])
            ->toBase()
            ->selectRaw(<<<'SQL'
                COALESCE(SUM(amount_cents), 0) AS balance,
                COALESCE(SUM(amount_cents) FILTER (WHERE amount_cents > 0), 0) AS inflow,
                COALESCE(SUM(amount_cents) FILTER (WHERE amount_cents < 0), 0) AS outflow
            SQL)
            ->first();

        return [
            'cashBalanceCents' => (int) $row->balance,
            'cashInflowCents' => (int) $row->inflow,
            // Valeur ABSOLUE : l'interface affiche « sorties » comme une
            // grandeur positive, le signe est porté par le libellé.
            'cashOutflowCents' => abs((int) $row->outflow),
        ];
    }

    /**
     * Cinq premiers clients par chiffre d'affaires de la période.
     *
     * Regroupé sur le TIERS quand la facture en porte un : le classement est
     * alors exact, y compris si le client a été renommé entre deux factures —
     * chaque document ayant figé le nom qu'il portait à l'émission (§3).
     *
     * Les factures sans tiers (clients de passage, données antérieures au
     * rattachement) retombent sur leur `client_name`. Le préfixe de la clé
     * évite qu'un nom libre ne fusionne par accident avec une fiche homonyme.
     *
     * `partnerId` est renvoyé pour que l'interface puisse pointer vers la fiche
     * — null quand la ligne vient d'un nom libre.
     *
     * @param  Collection<int, Document>  $invoices
     * @return list<array{name: string, partnerId: ?string, totalCents: int, invoiceCount: int}>
     */
    private function topClients(Collection $invoices): array
    {
        $ranked = $invoices
            ->groupBy(fn (Document $invoice): string => $invoice->partner_id !== null
                ? 'p:'.$invoice->partner_id
                : 'n:'.$invoice->client_name)
            ->map(function (Collection $rows): array {
                /** @var Document $first */
                $first = $rows->first();

                return [
                    // Le nom vient du document, pas de la fiche : c'est celui
                    // qui figure sur les factures agrégées.
                    'name' => $first->client_name,
                    'partnerId' => $first->partner_id,
                    'totalCents' => (int) $rows->sum('total_cents'),
                    'invoiceCount' => $rows->count(),
                ];
            })
            ->sortByDesc('totalCents')
            ->take(5)
            ->all();

        // array_values garantit la `list` annoncée : groupBy indexe par clé.
        return array_values($ranked);
    }

    /**
     * Somme des RÈGLEMENTS reçus, ventilée par facture.
     *
     * Une seule requête agrégée, en base : ramener les règlements ligne à ligne
     * pour les additionner ici coûterait autant de lignes que d'encaissements,
     * quand seul leur total par facture est utilisé.
     *
     * ── Les règlements, et non `documents.paid_cents` ─────────────────────
     *
     * La colonne existe et vaut la même chose : `PaymentWriteService::
     * refreshSettlement()` la recalcule à chaque écriture. Mais coïncider n'est
     * pas être la même chose — le jour où un import, une reprise de données ou
     * une écriture concurrente les fait diverger, c'est la table des règlements
     * qui dit la vérité, parce qu'elle porte les pièces. Même arbitrage que
     * `DocumentService::SETTLED_CENTS_SQL`, pour l'écran des situations.
     *
     * Le soft delete de `Payment` écarte d'office les règlements retirés : un
     * chèque revenu impayé ne doit plus compter dans l'encaissé.
     *
     * @param  Collection<int, Document>  $invoices
     * @return Collection<string, int> identifiant de facture → centimes reçus
     */
    private function collectedByInvoice(Collection $invoices): Collection
    {
        $ids = $invoices->pluck('id')->all();

        if ($ids === []) {
            /** @var Collection<string, int> $empty */
            $empty = new Collection;

            return $empty;
        }

        /** @var Collection<string, int> $totals */
        $totals = Payment::query()
            ->whereIn('invoice_id', $ids)
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(amount_cents) AS collected')
            ->pluck('collected', 'invoice_id')
            ->map(static fn (mixed $value): int => (int) $value);

        return $totals;
    }

    /**
     * @param  Collection<int, Document>  $invoices
     * @param  Collection<string, int>  $collectedByInvoice
     * @return list<array{month: string, invoicedCents: int, collectedCents: int}>
     */
    private function revenueSeries(
        Collection $invoices,
        Carbon $from,
        Carbon $to,
        Collection $collectedByInvoice,
    ): array {
        $series = [];
        $cursor = $from->copy()->startOfMonth();

        while ($cursor->lte($to)) {
            $monthKey = $cursor->format('Y-m');
            $monthRows = $invoices->filter(
                fn (Document $invoice): bool => $invoice->issued_at?->format('Y-m') === $monthKey,
            );

            $series[] = [
                'month' => $monthKey,
                'invoicedCents' => (int) $monthRows->sum('total_cents'),
                // Même correction que la carte « encaissé », et pour la même
                // raison : la courbe sommait le total des factures payées ou
                // partielles. Facturé et encaissé se superposaient donc dès
                // qu'un mois n'avait que des factures soldées, et le graphique
                // « facturé vs encaissé » ne montrait plus aucun écart — celui
                // qu'on vient précisément y chercher.
                'collectedCents' => (int) $monthRows->sum(
                    fn (Document $invoice): int => (int) $collectedByInvoice->get($invoice->id, 0),
                ),
            ];

            $cursor->addMonth();
        }

        return $series;
    }

    /**
     * @param  Collection<int, Document>  $invoices
     * @return list<array{status: string, count: int, totalCents: int}>
     */
    private function statusBreakdown(Collection $invoices): array
    {
        $breakdown = $invoices
            ->groupBy('status')
            ->map(fn (Collection $rows, string $status): array => [
                'status' => $status,
                'count' => $rows->count(),
                'totalCents' => (int) $rows->sum('total_cents'),
            ])
            ->all();

        // array_values garantit la `list` annoncée : groupBy indexe par statut.
        return array_values($breakdown);
    }

    private function trend(int $current, int $previous): float
    {
        if ($previous === 0) {
            return $current > 0 ? 1.0 : 0.0;
        }

        return round(($current - $previous) / $previous, 4);
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

    /** @return array{0: Carbon, 1: Carbon} */
    private function previousPeriod(Carbon $from, Carbon $to): array
    {
        $days = $from->diffInDays($to) + 1;

        return [$from->copy()->subDays($days), $from->copy()->subDay()];
    }
}
