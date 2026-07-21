<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\Services\DashboardStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardStatsController
{
    public function __construct(private readonly DashboardStatsService $stats) {}

    public function __invoke(Request $request): JsonResponse
    {
        $period = $request->string('period', 'last30')->toString();

        return response()->json($this->stats->forPeriod($period));
    }
}
