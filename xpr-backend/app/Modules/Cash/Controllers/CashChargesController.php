<?php

declare(strict_types=1);

namespace App\Modules\Cash\Controllers;

use App\Modules\Cash\Models\CashMovement;
use Illuminate\Http\JsonResponse;

/**
 * Natures de charge DÉJÀ EMPLOYÉES par la société, par ordre alphabétique.
 *
 * Alimente le champ « Charge » du formulaire de décaissement : la liste se
 * construit à l'usage, sans référentiel à provisionner ni écran de gestion à
 * livrer (cf. la migration `add_charge_to_cash_movements`). Le champ reste
 * LIBRE — cette liste propose, elle n'impose pas.
 *
 * Pourquoi un endpoint et non un dérivé du journal déjà chargé par l'écran :
 * celui-ci est borné à une PÉRIODE. Les charges d'un exercice ne se limitent
 * pas aux trente derniers jours, et un déroulant qui oublierait « Loyer » parce
 * qu'aucun loyer n'a été payé ce mois-ci pousserait à le ressaisir — donc à
 * créer un doublon d'orthographe, exactement ce que la liste sert à éviter.
 */
final class CashChargesController
{
    public function __invoke(): JsonResponse
    {
        /** @var list<string> $charges */
        $charges = CashMovement::query()
            ->whereNotNull('charge')
            ->distinct()
            ->orderBy('charge')
            ->pluck('charge')
            ->all();

        return response()->json(['data' => $charges]);
    }
}
