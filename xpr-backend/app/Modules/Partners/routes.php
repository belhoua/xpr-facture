<?php

declare(strict_types=1);

use App\Modules\Partners\Controllers\PartnerArchiveController;
use App\Modules\Partners\Controllers\PartnerListController;
use App\Modules\Partners\Controllers\PartnerShowController;
use App\Modules\Partners\Controllers\PartnerStoreController;
use App\Modules\Partners\Controllers\PartnerUpdateController;
use App\Modules\Tenancy\Enums\Permission;
use Illuminate\Support\Facades\Route;

// Chargées par PartnersServiceProvider. Chaque route porte sa permission,
// LECTURE COMPRISE (§10).
//
// {partner} est un simple paramètre de route, PAS un binding de modèle : le
// tiers est résolu par PartnerService sous le scope tenant. SubstituteBindings
// s'exécute avant 'tenant' et le résoudrait hors société
// (cf. tests/Feature/Tenancy/RouteBindingScopeTest.php).
Route::middleware(['api', 'auth:sanctum', 'tenant'])
    ->prefix('api/v1')
    ->group(function (): void {
        Route::get('partners', PartnerListController::class)
            ->middleware('permission:'.Permission::PartnersView->value);
        Route::get('partners/{partner}', PartnerShowController::class)
            ->middleware('permission:'.Permission::PartnersView->value);
        Route::post('partners', PartnerStoreController::class)
            ->middleware('permission:'.Permission::PartnersCreate->value);
        Route::patch('partners/{partner}', PartnerUpdateController::class)
            ->middleware('permission:'.Permission::PartnersUpdate->value);
        // Archivage et non suppression : les documents commerciaux qui
        // référencent ce tiers doivent rester lisibles.
        Route::delete('partners/{partner}', PartnerArchiveController::class)
            ->middleware('permission:'.Permission::PartnersDelete->value);
    });
