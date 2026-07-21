<?php

declare(strict_types=1);

use App\Modules\Tenancy\Controllers\CompanyUserListController;
use App\Modules\Tenancy\Controllers\InviteUserController;
use App\Modules\Tenancy\Enums\Permission;
use Illuminate\Support\Facades\Route;

// Chargées par TenancyServiceProvider. Les collaborateurs sont ceux de la
// société active : le périmètre vient de TenantContext, jamais d'un paramètre
// de requête (§5.3).
Route::middleware(['api', 'auth:sanctum', 'tenant'])
    ->prefix('api/v1')
    ->group(function (): void {
        Route::get('users', CompanyUserListController::class)
            ->middleware('permission:'.Permission::UsersView->value);
        Route::post('users/invitations', InviteUserController::class)
            ->middleware(['throttle:invitations', 'permission:'.Permission::UsersInvite->value]);
    });
