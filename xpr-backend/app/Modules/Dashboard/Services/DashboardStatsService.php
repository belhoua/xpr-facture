<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Invoices\Models\Invoice;
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
     *     statusBreakdown: list<array{status: string, count: int, totalCents: int}>
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
        ];
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
