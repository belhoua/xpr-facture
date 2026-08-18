<?php

declare(strict_types=1);

use App\Modules\Services\Controllers\ServiceListController;
use App\Modules\Services\Controllers\ServiceStoreController;
use App\Modules\Tenancy\Enums\Permission;
use Illuminate\Support\Facades\Route;

// Chargées par ServicesServiceProvider. 'tenant' se place APRÈS 'auth:sanctum' :
// il résout la société depuis l'utilisateur authentifié et arme le scope
// Eloquent + la RLS (§5).
//
// Chaque route porte sa permission, LECTURE COMPRISE (§10).
Route::middleware(['api', 'auth:sanctum', 'tenant'])
    ->prefix('api/v1')
    ->group(function (): void {
        Route::get('services', ServiceListController::class)
            ->middleware('permission:'.Permission::ServicesView->value);
        Route::post('services', ServiceStoreController::class)
            ->middleware('permission:'.Permission::ServicesManage->value);
    });
