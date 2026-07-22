<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée du module Accounting.
 *
 * Le module n'avait longtemps ni routes ni provider : il ne portait que des
 * référentiels (exercices, séquences, taux de TVA) consommés en interne. Le
 * provider apparaît avec le premier endpoint réellement nécessaire — le
 * référentiel des taux de TVA, que le catalogue et l'éditeur de documents
 * doivent lire pour proposer un taux.
 */
final class AccountingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}
