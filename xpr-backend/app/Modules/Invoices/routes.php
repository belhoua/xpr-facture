<?php

declare(strict_types=1);

use App\Modules\Invoices\Controllers\InvoiceCancelController;
use App\Modules\Invoices\Controllers\InvoiceDeleteController;
use App\Modules\Invoices\Controllers\InvoiceListController;
use App\Modules\Invoices\Controllers\InvoiceStoreController;
use App\Modules\Invoices\Controllers\InvoiceUpdateController;
use App\Modules\Tenancy\Enums\Permission;
use Illuminate\Support\Facades\Route;

// Chargées par InvoicesServiceProvider. Le groupe 'api' apporte statefulApi
// (sessions Sanctum) et SetLocale (cf. bootstrap/app.php).
// 'tenant' se place APRÈS 'auth:sanctum' : il résout la société depuis
// l'utilisateur authentifié et arme le scope Eloquent + la RLS (§5).
//
// Le binding implicite {invoice} traverse le scope BelongsToCompany : une
// facture d'une autre société renvoie 404 sans jamais atteindre le contrôleur.
// Chaque route porte sa permission, y compris en LECTURE (§10) : le middleware
// 'permission' vient après 'tenant', qui a posé le périmètre Spatie.
Route::middleware(['api', 'auth:sanctum', 'tenant'])
    ->prefix('api/v1')
    ->group(function (): void {
        Route::get('invoices', InvoiceListController::class)
            ->middleware('permission:'.Permission::InvoicesView->value);
        Route::post('invoices', InvoiceStoreController::class)
            ->middleware('permission:'.Permission::InvoicesCreate->value);
        Route::patch('invoices/{invoice}', InvoiceUpdateController::class)
            ->middleware('permission:'.Permission::InvoicesUpdate->value);
        Route::delete('invoices/{invoice}', InvoiceDeleteController::class)
            ->middleware('permission:'.Permission::InvoicesDelete->value);
        // Annulation = seul changement d'état permis sur une facture validée (§3).
        // Acte fiscal : permission distincte de la simple édition, le rôle
        // `sales` ne l'a pas.
        Route::post('invoices/{invoice}/cancel', InvoiceCancelController::class)
            ->middleware('permission:'.Permission::InvoicesCancel->value);
    });
