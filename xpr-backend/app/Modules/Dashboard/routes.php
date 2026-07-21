<?php

declare(strict_types=1);

use App\Modules\Dashboard\Controllers\DashboardStatsController;
use App\Modules\Tenancy\Enums\Permission;
use Illuminate\Support\Facades\Route;

// Chargées par DashboardServiceProvider. Le front appelle `/dashboard/stats`
// (cf. features/dashboard/api/dashboard.ts) ; `/dashboard` reste un alias.
Route::middleware(['api', 'auth:sanctum', 'tenant'])
    ->prefix('api/v1')
    ->group(function (): void {
        Route::get('dashboard/stats', DashboardStatsController::class)
            ->middleware('permission:'.Permission::DashboardView->value);
        Route::get('dashboard', DashboardStatsController::class)
            ->middleware('permission:'.Permission::DashboardView->value);
    });
