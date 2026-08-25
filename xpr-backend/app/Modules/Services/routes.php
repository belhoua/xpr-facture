<?php

declare(strict_types=1);

use App\Modules\Services\Controllers\ServiceListController;
use App\Modules\Services\Controllers\ServiceStoreController;
use App\Modules\Tenancy\Enums\Permission;
use Illuminate\Support\Facades\Route;

// ⚠️ MODULE DORMANT depuis le 2026-08-26.
//
// Ces deux routes servaient le référentiel de classement des projets. Elles
// n'ont plus de consommateur : `projects.service_id` pointe désormais le
// CATALOGUE (`products` de type « service »), la seule liste que l'écran
// `/services` alimente réellement — cf. la migration
// `point_project_service_to_catalog`, qui explique le doublon de nommage et ce
// qu'il coûtait.
//
// Le module reste debout et sa table conserve ses données, reprises dans le
// catalogue par la migration. NE PAS y rebrancher un écran : ce serait
// recréer le second référentiel, et le déroulant du projet redeviendrait vide.
//
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
