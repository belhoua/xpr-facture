<?php

declare(strict_types=1);

namespace App\Modules\Partners\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée du module Partners : répertoire des clients et fournisseurs
 * de la société active.
 */
final class PartnersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}
