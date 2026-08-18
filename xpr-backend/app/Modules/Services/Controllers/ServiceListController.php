<?php

declare(strict_types=1);

namespace App\Modules\Services\Controllers;

use App\Modules\Services\Models\Service;
use App\Modules\Services\Resources\ServiceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Référentiel des services de la société active.
 *
 * NON PAGINÉ, à la différence des tiers et du catalogue : cette liste alimente
 * un déroulant, et un référentiel de classement compte des dizaines d'entrées,
 * pas des milliers. Paginer obligerait l'écran à charger page après page pour
 * proposer un choix complet — ou, pire, à n'en proposer qu'une partie sans le
 * dire.
 */
final class ServiceListController
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = Service::query();

        if (($search = $request->string('search')->toString()) !== '') {
            $query->search($search);
        }

        return response()->json([
            'data' => ServiceResource::collection(
                $query->orderBy('name')->get(),
            )->resolve(),
        ]);
    }
}
