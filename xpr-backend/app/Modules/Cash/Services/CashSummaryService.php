<?php

declare(strict_types=1);

namespace App\Modules\Cash\Services;

use App\Modules\Cash\Models\CashMovement;
use App\Modules\Cash\Resources\CashMovementResource;
use Illuminate\Support\Carbon;

final class CashSummaryService
{
    /**
     * @return array{
     *     balanceCents: int,
     *     inflowCents: int,
     *     outflowCents: int,
     *     currency: string,
     *     movements: list<array<string, mixed>>
     * }
     */
    public function summarize(string $period): array
    {
        [$from, $to] = $this->resolvePeriod($period);

        $movements = CashMovement::query()
            ->whereBetween('occurred_at', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->get();

        $inflowCents = (int) $movements->where('amount_cents', '>', 0)->sum('amount_cents');
        $outflowCents = (int) abs((int) $movements->where('amount_cents', '<', 0)->sum('amount_cents'));
        $balanceCents = (int) $movements->sum('amount_cents');

        // `first()` renvoie null sur une période sans mouvement — Larastan le
        // type comme non-nul, d'où le test explicite plutôt qu'un `?->`.
        $firstMovement = $movements->first();
        $currency = $firstMovement instanceof CashMovement ? $firstMovement->currency : 'MAD';

        /** @var list<array<string, mixed>> $rows */
        $rows = CashMovementResource::collection($movements)->resolve();

        return [
            'balanceCents' => $balanceCents,
            'inflowCents' => $inflowCents,
            'outflowCents' => $outflowCents,
            'currency' => $currency,
            'movements' => $rows,
        ];
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
