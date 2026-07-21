<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Cash\Models\CashMovement;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Partners\Enums\PartnerType;
use App\Modules\Partners\Models\Partner;
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

        $invoices = Invoice::query()
            ->where('status', '!=', 'cancelled')
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

        $previousInvoices = Invoice::query()
            ->where('status', '!=', 'cancelled')
            ->whereBetween('issued_at', [$previousFrom->toDateString(), $previousTo->toDateString()])
            ->get();

        $revenueCents = (int) $invoices->sum('total_cents');
        $previousRevenueCents = (int) $previousInvoices->sum('total_cents');

        $collectedCents = (int) $invoices->whereIn('status', ['paid', 'partial'])->sum('total_cents');
        $outstandingCents = (int) $invoices->whereIn('status', ['sent', 'partial', 'overdue'])->sum('total_cents');
        $overdueRows = $invoices->where('status', 'overdue');
        $overdueCents = (int) $overdueRows->sum('total_cents');
        $overdueCount = $overdueRows->count();

        // Voir CashSummaryService : `first()` est nul sur une période vide,
        // Larastan le type comme non-nul.
        $firstInvoice = $invoices->first();

        return [
            'currency' => $firstInvoice instanceof Invoice ? $firstInvoice->currency : 'MAD',
            'revenueCents' => $revenueCents,
            'revenueTrend' => $this->trend($revenueCents, $previousRevenueCents),
            'collectedCents' => $collectedCents,
            'outstandingCents' => $outstandingCents,
            'overdueCents' => $overdueCents,
            'overdueCount' => $overdueCount,
            'revenueSeries' => $this->revenueSeries($invoices, $from, $to),
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
        $movements = CashMovement::query()
            ->whereBetween('occurred_at', [$from->toDateString(), $to->toDateString()])
            ->get();

        return [
            'cashBalanceCents' => (int) $movements->sum('amount_cents'),
            'cashInflowCents' => (int) $movements->where('amount_cents', '>', 0)->sum('amount_cents'),
            // Valeur ABSOLUE : l'interface affiche « sorties » comme une
            // grandeur positive, le signe est porté par le libellé.
            'cashOutflowCents' => (int) abs((int) $movements->where('amount_cents', '<', 0)->sum('amount_cents')),
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
     * @param  Collection<int, Invoice>  $invoices
     * @return list<array{name: string, partnerId: ?string, totalCents: int, invoiceCount: int}>
     */
    private function topClients(Collection $invoices): array
    {
        $ranked = $invoices
            ->groupBy(fn (Invoice $invoice): string => $invoice->partner_id !== null
                ? 'p:'.$invoice->partner_id
                : 'n:'.$invoice->client_name)
            ->map(function (Collection $rows): array {
                /** @var Invoice $first */
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
     * @param  Collection<int, Invoice>  $invoices
     * @return list<array{month: string, invoicedCents: int, collectedCents: int}>
     */
    private function revenueSeries(Collection $invoices, Carbon $from, Carbon $to): array
    {
        $series = [];
        $cursor = $from->copy()->startOfMonth();

        while ($cursor->lte($to)) {
            $monthKey = $cursor->format('Y-m');
            $monthRows = $invoices->filter(
                fn (Invoice $invoice): bool => $invoice->issued_at?->format('Y-m') === $monthKey,
            );

            $series[] = [
                'month' => $monthKey,
                'invoicedCents' => (int) $monthRows->sum('total_cents'),
                'collectedCents' => (int) $monthRows
                    ->whereIn('status', ['paid', 'partial'])
                    ->sum('total_cents'),
            ];

            $cursor->addMonth();
        }

        return $series;
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
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
