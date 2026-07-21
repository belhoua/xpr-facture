<?php

declare(strict_types=1);

namespace App\Modules\Cash\Controllers;

use App\Modules\Cash\Services\CashSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CashMovementsController
{
    public function __construct(private readonly CashSummaryService $cash) {}

    public function __invoke(Request $request): JsonResponse
    {
        $period = $request->string('period', 'last30')->toString();

        return response()->json($this->cash->summarize($period));
    }
}
