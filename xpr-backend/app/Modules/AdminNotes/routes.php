<?php

declare(strict_types=1);

use App\Modules\AdminNotes\Controllers\AdminNoteListController;
use App\Modules\AdminNotes\Controllers\CreateAdminNoteController;
use App\Modules\Tenancy\Enums\Permission;
use Illuminate\Support\Facades\Route;

// Chargées par AdminNotesServiceProvider. Le périmètre est celui de la société
// active : il vient de TenantContext, jamais d'un paramètre de requête (§5.3).
Route::middleware(['api', 'auth:sanctum', 'tenant'])
    ->prefix('api/v1')
    ->group(function (): void {
        Route::get('admin-notes', AdminNoteListController::class)
            ->middleware('permission:'.Permission::AdminNotesView->value);
        Route::post('admin-notes', CreateAdminNoteController::class)
            ->middleware(['throttle:admin-notes', 'permission:'.Permission::AdminNotesCreate->value]);
    });
