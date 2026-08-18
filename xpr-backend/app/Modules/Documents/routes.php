<?php

declare(strict_types=1);

use App\Modules\Documents\Controllers\DocumentCancelController;
use App\Modules\Documents\Controllers\DocumentConvertController;
use App\Modules\Documents\Controllers\DocumentDeleteController;
use App\Modules\Documents\Controllers\DocumentIssueController;
use App\Modules\Documents\Controllers\DocumentListController;
use App\Modules\Documents\Controllers\DocumentShowController;
use App\Modules\Documents\Controllers\DocumentStatusController;
use App\Modules\Documents\Controllers\DocumentStoreController;
use App\Modules\Documents\Controllers\DocumentSummaryController;
use App\Modules\Documents\Controllers\DocumentUpdateController;
use App\Modules\Tenancy\Enums\Permission;
use Illuminate\Support\Facades\Route;

// Chargées par DocumentsServiceProvider. Le groupe 'api' apporte statefulApi
// (sessions Sanctum) et SetLocale ; 'tenant' se place APRÈS 'auth:sanctum' —
// il résout la société depuis l'utilisateur authentifié et arme le scope
// Eloquent + la RLS (§5).
//
// {document} est un simple paramètre de route, PAS un binding de modèle :
// SubstituteBindings s'exécute avant 'tenant' et exposerait le document d'une
// autre société (cf. tests/Feature/Tenancy/RouteBindingScopeTest.php).
//
// Chaque route porte sa permission, LECTURE COMPRISE (§10).
Route::middleware(['api', 'auth:sanctum', 'tenant'])
    ->prefix('api/v1')
    ->group(function (): void {
        Route::get('documents', DocumentListController::class)
            ->middleware('permission:'.Permission::DocumentsView->value);

        // AVANT `documents/{document}`, impérativement : Laravel retient la
        // première route qui correspond, et `{document}` capturerait « summary »
        // comme un identifiant — la requête finirait en 404 sans rien indiquer
        // de la cause.
        Route::get('documents/summary', DocumentSummaryController::class)
            ->middleware('permission:'.Permission::DocumentsView->value);

        Route::get('documents/{document}', DocumentShowController::class)
            ->middleware('permission:'.Permission::DocumentsView->value);

        Route::post('documents', DocumentStoreController::class)
            ->middleware('permission:'.Permission::DocumentsCreate->value);
        Route::patch('documents/{document}', DocumentUpdateController::class)
            ->middleware('permission:'.Permission::DocumentsUpdate->value);
        // Brouillons uniquement : le service répond 409 sur un document émis.
        Route::delete('documents/{document}', DocumentDeleteController::class)
            ->middleware('permission:'.Permission::DocumentsDelete->value);

        // Émission : attribue le numéro fiscal et gèle le document. Permission
        // propre — c'est l'acte engageant, pas la sauvegarde qui le précède.
        Route::post('documents/{document}/issue', DocumentIssueController::class)
            ->middleware('permission:'.Permission::DocumentsIssue->value);

        // Devis accepté/refusé, facture réglée/échue. Reste sous la permission
        // d'édition : suivre l'avancement commercial n'est pas un acte fiscal.
        Route::post('documents/{document}/status', DocumentStatusController::class)
            ->middleware('permission:'.Permission::DocumentsUpdate->value);

        // Annulation d'un document VALIDÉ : acte fiscal, le rôle `sales` ne
        // l'a pas.
        Route::post('documents/{document}/cancel', DocumentCancelController::class)
            ->middleware('permission:'.Permission::DocumentsCancel->value);

        // Conversion devis → facture : crée un document, d'où `documents.create`.
        Route::post('documents/{document}/convert', DocumentConvertController::class)
            ->middleware('permission:'.Permission::DocumentsCreate->value);
    });
