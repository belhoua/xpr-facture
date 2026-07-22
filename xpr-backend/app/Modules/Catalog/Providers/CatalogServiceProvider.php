<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée du module Catalog : produits, services et leurs catégories.
 */
final class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}
