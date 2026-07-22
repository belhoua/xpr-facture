<?php

declare(strict_types=1);

use App\Modules\Accounting\Controllers\TaxRateListController;
use App\Modules\Tenancy\Enums\Permission;
use Illuminate\Support\Facades\Route;

// Chargées par AccountingServiceProvider.
//
// `catalog.view` et non une permission dédiée : les taux de TVA sont un
// référentiel du catalogue, et tout rôle qui peut lire un article ou un
// document peut déjà lire les taux qui s'y appliquent. Créer
// `accounting.view` pour l'accorder aux mêmes rôles n'ajouterait qu'une ligne
// de matrice à maintenir.
Route::middleware(['api', 'auth:sanctum', 'tenant'])
    ->prefix('api/v1')
    ->group(function (): void {
        Route::get('tax-rates', TaxRateListController::class)
            ->middleware('permission:'.Permission::CatalogView->value);
    });
