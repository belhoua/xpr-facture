<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Models\TaxRate;
use App\Modules\Accounting\Resources\TaxRateResource;
use Illuminate\Http\JsonResponse;

/**
 * Référentiel des taux de TVA applicables par la société.
 *
 * Lecture seule à ce stade : le catalogue standard marocain est fourni par le
 * seeder, et rien dans le produit ne permet encore d'en créer un propre. Exposer
 * un CRUD dont aucun écran n'a besoin serait une surface d'attaque gratuite.
 *
 * Pas de pagination : sept taux au maximum, la pagination n'apporterait qu'une
 * enveloppe à déballer côté client.
 *
 * BelongsToCompanyOrGlobal renvoie les taux de la société ET le catalogue
 * partagé (company_id NULL) — c'est exactement ce que le formulaire attend.
 */
final class TaxRateListController
{
    public function __invoke(): JsonResponse
    {
        $rates = TaxRate::query()
            ->active()
            ->orderBy('rate')
            ->get();

        return response()->json([
            'data' => TaxRateResource::collection($rates)->resolve(),
        ]);
    }
}
