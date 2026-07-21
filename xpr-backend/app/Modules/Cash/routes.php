<?php

declare(strict_types=1);

use App\Modules\Cash\Controllers\CashMovementDeleteController;
use App\Modules\Cash\Controllers\CashMovementsController;
use App\Modules\Cash\Controllers\CashMovementStoreController;
use App\Modules\Cash\Controllers\CashMovementUpdateController;
use App\Modules\Tenancy\Enums\Permission;
use Illuminate\Support\Facades\Route;

// Chargées par CashServiceProvider. Le front appelle `/cash/movements`
// (cf. features/cash/api/cash.ts) ; `/cash` est conservé comme alias de lecture.
//
// Le binding implicite {movement} traverse le scope BelongsToCompany : un
// mouvement d'une autre société renvoie 404 (§5).
Route::middleware(['api', 'auth:sanctum', 'tenant'])
    ->prefix('api/v1')
    ->group(function (): void {
        Route::get('cash/movements', CashMovementsController::class)
            ->middleware('permission:'.Permission::CashView->value);
        Route::get('cash', CashMovementsController::class)
            ->middleware('permission:'.Permission::CashView->value);
        Route::post('cash/movements', CashMovementStoreController::class)
            ->middleware('permission:'.Permission::CashManage->value);
        Route::patch('cash/movements/{movement}', CashMovementUpdateController::class)
            ->middleware('permission:'.Permission::CashManage->value);
        Route::delete('cash/movements/{movement}', CashMovementDeleteController::class)
            ->middleware('permission:'.Permission::CashManage->value);
    });
