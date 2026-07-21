<?php

declare(strict_types=1);

use App\Modules\Invoices\Controllers\InvoiceCancelController;
use App\Modules\Invoices\Controllers\InvoiceDeleteController;
use App\Modules\Invoices\Controllers\InvoiceListController;
use App\Modules\Invoices\Controllers\InvoiceStoreController;
use App\Modules\Invoices\Controllers\InvoiceUpdateController;
use Illuminate\Support\Facades\Route;

// Chargées par InvoicesServiceProvider. Le groupe 'api' apporte statefulApi
// (sessions Sanctum) et SetLocale (cf. bootstrap/app.php).
// 'tenant' se place APRÈS 'auth:sanctum' : il résout la société depuis
// l'utilisateur authentifié et arme le scope Eloquent + la RLS (§5).
//
// Le binding implicite {invoice} traverse le scope BelongsToCompany : une
// facture d'une autre société renvoie 404 sans jamais atteindre le contrôleur.
Route::middleware(['api', 'auth:sanctum', 'tenant'])
    ->prefix('api/v1')
    ->group(function (): void {
        Route::get('invoices', InvoiceListController::class);
        Route::post('invoices', InvoiceStoreController::class);
        Route::patch('invoices/{invoice}', InvoiceUpdateController::class);
        Route::delete('invoices/{invoice}', InvoiceDeleteController::class);
        // Annulation = seul changement d'état permis sur une facture validée (§3).
        Route::post('invoices/{invoice}/cancel', InvoiceCancelController::class);
    });
