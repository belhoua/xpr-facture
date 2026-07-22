<?php

declare(strict_types=1);

namespace App\Modules\Documents\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée du module Documents : le moteur unique des devis, factures,
 * avoirs et types dérivés.
 */
final class DocumentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}
