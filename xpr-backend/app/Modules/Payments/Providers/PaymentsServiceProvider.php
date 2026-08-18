<?php

declare(strict_types=1);

namespace App\Modules\Payments\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée du module Règlements : encaissements reçus sur les factures.
 *
 * Distinct du module Cash, qui suit les mouvements d'une caisse : ici, chaque
 * écriture SOLDE une créance nominative et fait bouger le statut d'une pièce
 * fiscale.
 */
final class PaymentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}
