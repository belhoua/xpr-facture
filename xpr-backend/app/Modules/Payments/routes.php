<?php

declare(strict_types=1);

use App\Modules\Payments\Controllers\PaymentDeleteController;
use App\Modules\Payments\Controllers\PaymentListController;
use App\Modules\Payments\Controllers\PaymentScanController;
use App\Modules\Payments\Controllers\PaymentStoreController;
use App\Modules\Tenancy\Enums\Permission;
use Illuminate\Support\Facades\Route;

// Chargées par PaymentsServiceProvider. Le groupe 'api' apporte statefulApi
// (sessions Sanctum) et SetLocale ; 'tenant' se place APRÈS 'auth:sanctum' —
// il résout la société depuis l'utilisateur authentifié et arme le scope
// Eloquent + la RLS (§5).
//
// {invoice} et {payment} sont de simples paramètres de route, PAS des bindings
// de modèle : SubstituteBindings s'exécute avant 'tenant' et exposerait la
// facture — donc les règlements — d'une autre société
// (cf. tests/Feature/Tenancy/RouteBindingScopeTest.php).
//
// Chaque route porte sa permission, LECTURE COMPRISE (§10).
Route::middleware(['api', 'auth:sanctum', 'tenant'])
    ->prefix('api/v1')
    ->group(function (): void {
        Route::get('invoices/{invoice}/payments', PaymentListController::class)
            ->middleware('permission:'.Permission::PaymentsView->value);

        Route::post('invoices/{invoice}/payments', PaymentStoreController::class)
            ->middleware('permission:'.Permission::PaymentsManage->value);

        // Suppression par l'identifiant du RÈGLEMENT, sans la facture dans le
        // chemin : un règlement n'appartient qu'à une facture, la répéter dans
        // l'URL ouvrirait la question de leur désaccord.
        Route::delete('payments/{payment}', PaymentDeleteController::class)
            ->middleware('permission:'.Permission::PaymentsManage->value);

        // Le scan est servi par l'application, jamais par le serveur web : le
        // disque est hors webroot et la lecture exige la société ET la
        // permission. Un chèque scanné porte un RIB et une signature.
        Route::get('payments/{payment}/scan', PaymentScanController::class)
            ->middleware('permission:'.Permission::PaymentsView->value);
    });
