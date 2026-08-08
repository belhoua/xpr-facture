<?php

declare(strict_types=1);

use App\Modules\Conventions\Controllers\ConventionDeleteController;
use App\Modules\Conventions\Controllers\ConventionFromDocumentController;
use App\Modules\Conventions\Controllers\ConventionListController;
use App\Modules\Conventions\Controllers\ConventionShowController;
use App\Modules\Conventions\Controllers\ConventionStoreController;
use App\Modules\Conventions\Controllers\ConventionUpdateController;
use App\Modules\Conventions\Controllers\FileDepositDeleteController;
use App\Modules\Conventions\Controllers\FileDepositListController;
use App\Modules\Conventions\Controllers\FileDepositShowController;
use App\Modules\Conventions\Controllers\FileDepositStoreController;
use App\Modules\Conventions\Controllers\FileDepositUpdateController;
use App\Modules\Tenancy\Enums\Permission;
use Illuminate\Support\Facades\Route;

// Chargées par ConventionsServiceProvider. Le groupe 'api' apporte statefulApi
// (sessions Sanctum) et SetLocale ; 'tenant' se place APRÈS 'auth:sanctum' — il
// résout la société depuis l'utilisateur authentifié et arme le scope Eloquent
// + la RLS (§5).
//
// {convention}, {deposit} et {document} sont des paramètres de route SIMPLES,
// pas des bindings de modèle : SubstituteBindings s'exécute avant 'tenant' et
// résoudrait l'objet d'une autre société
// (cf. tests/Feature/Tenancy/RouteBindingScopeTest.php).
//
// Chaque route porte sa permission, LECTURE COMPRISE (§10).
Route::middleware(['api', 'auth:sanctum', 'tenant'])
    ->prefix('api/v1')
    ->group(function (): void {
        Route::get('conventions', ConventionListController::class)
            ->middleware('permission:'.Permission::ConventionsView->value);

        // AVANT `conventions/{convention}` : Laravel retient la première route
        // qui correspond, et `{convention}` capturerait « from-document ». Sans
        // ce placement, le transfert finirait en 404 sans rien dire de la cause.
        Route::post('conventions/from-document/{document}', ConventionFromDocumentController::class)
            ->middleware('permission:'.Permission::ConventionsCreate->value);

        Route::get('conventions/{convention}', ConventionShowController::class)
            ->middleware('permission:'.Permission::ConventionsView->value);
        Route::post('conventions', ConventionStoreController::class)
            ->middleware('permission:'.Permission::ConventionsCreate->value);
        Route::patch('conventions/{convention}', ConventionUpdateController::class)
            ->middleware('permission:'.Permission::ConventionsUpdate->value);
        // Le service répond 409 sur une convention SIGNÉE.
        Route::delete('conventions/{convention}', ConventionDeleteController::class)
            ->middleware('permission:'.Permission::ConventionsDelete->value);

        // Dépôts de dossier. La CRÉATION est imbriquée sous la convention — le
        // rattachement vient du chemin, jamais du corps de la requête (§5.3) —
        // tandis que la lecture, la correction et le retrait s'adressent au
        // dépôt lui-même : il est déjà résolu sous le scope tenant, exiger la
        // convention dans l'URL n'ajouterait aucune garantie.
        Route::get('deposits', FileDepositListController::class)
            ->middleware('permission:'.Permission::DepositsView->value);
        Route::get('deposits/{deposit}', FileDepositShowController::class)
            ->middleware('permission:'.Permission::DepositsView->value);
        Route::post('conventions/{convention}/deposits', FileDepositStoreController::class)
            ->middleware('permission:'.Permission::DepositsManage->value);
        Route::patch('deposits/{deposit}', FileDepositUpdateController::class)
            ->middleware('permission:'.Permission::DepositsManage->value);
        Route::delete('deposits/{deposit}', FileDepositDeleteController::class)
            ->middleware('permission:'.Permission::DepositsManage->value);
    });
