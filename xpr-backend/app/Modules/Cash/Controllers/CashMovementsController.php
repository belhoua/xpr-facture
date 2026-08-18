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

        // `direction` ne filtre que la LISTE — les trois cumuls restent
        // calculés sur toute la période (cf. CashSummaryService::summarize).
        // Une valeur inconnue est ignorée plutôt que rejetée : c'est un confort
        // d'affichage, pas une clause métier, et un 422 ici casserait l'écran
        // pour un paramètre décoratif.
        $direction = $request->string('direction')->toString();
        $direction = in_array($direction, ['inflow', 'outflow'], strict: true)
            ? $direction
            : null;

        return response()->json($this->cash->summarize($period, $direction));
    }
}
