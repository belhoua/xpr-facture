<?php

declare(strict_types=1);

use App\Modules\Projects\Controllers\DeliverableDeleteController;
use App\Modules\Projects\Controllers\DeliverableStoreController;
use App\Modules\Projects\Controllers\ProjectDeleteController;
use App\Modules\Projects\Controllers\ProjectListController;
use App\Modules\Projects\Controllers\ProjectShowController;
use App\Modules\Projects\Controllers\ProjectStoreController;
use App\Modules\Projects\Controllers\ProjectSummaryController;
use App\Modules\Projects\Controllers\ProjectUpdateController;
use App\Modules\Tenancy\Enums\Permission;
use Illuminate\Support\Facades\Route;

// Chargées par ProjectsServiceProvider. Le groupe 'api' apporte statefulApi
// (sessions Sanctum) et SetLocale ; 'tenant' se place APRÈS 'auth:sanctum' — il
// résout la société depuis l'utilisateur authentifié et arme le scope Eloquent
// + la RLS (§5).
//
// {project} et {deliverable} sont des paramètres de route SIMPLES, pas des
// bindings de modèle : SubstituteBindings s'exécute avant 'tenant' et
// résoudrait l'objet d'une autre société
// (cf. tests/Feature/Tenancy/RouteBindingScopeTest.php).
//
// Chaque route porte sa permission, LECTURE COMPRISE (§10).
Route::middleware(['api', 'auth:sanctum', 'tenant'])
    ->prefix('api/v1')
    ->group(function (): void {
        Route::get('projects', ProjectListController::class)
            ->middleware('permission:'.Permission::ProjectsView->value);
        // AVANT `projects/{project}` : Laravel retient la première route qui
        // matche, et le paramètre libre capterait « summary » comme s'il
        // s'agissait d'un identifiant — 404 sur un endpoint pourtant déclaré.
        Route::get('projects/summary', ProjectSummaryController::class)
            ->middleware('permission:'.Permission::ProjectsView->value);
        Route::get('projects/{project}', ProjectShowController::class)
            ->middleware('permission:'.Permission::ProjectsView->value);

        Route::post('projects', ProjectStoreController::class)
            ->middleware('permission:'.Permission::ProjectsCreate->value);
        // Sert aussi la mise à jour de l'avancement : le PATCH n'écrit que les
        // clés reçues, l'écran de détail ne pousse que `status` et
        // `progressPercentage`.
        Route::patch('projects/{project}', ProjectUpdateController::class)
            ->middleware('permission:'.Permission::ProjectsUpdate->value);
        Route::delete('projects/{project}', ProjectDeleteController::class)
            ->middleware('permission:'.Permission::ProjectsDelete->value);

        // Livrables. La CRÉATION est imbriquée sous le projet — le rattachement
        // vient du chemin, jamais du corps de la requête (§5.3) — tandis que le
        // retrait s'adresse au livrable lui-même : il est déjà résolu sous le
        // scope tenant, exiger le projet dans l'URL n'ajouterait aucune
        // garantie.
        //
        // Sous `projects.update` et non une permission propre : ajouter une
        // remise, c'est mettre à jour l'avancement du projet.
        Route::post('projects/{project}/deliverables', DeliverableStoreController::class)
            ->middleware('permission:'.Permission::ProjectsUpdate->value);
        Route::delete('deliverables/{deliverable}', DeliverableDeleteController::class)
            ->middleware('permission:'.Permission::ProjectsUpdate->value);
    });
