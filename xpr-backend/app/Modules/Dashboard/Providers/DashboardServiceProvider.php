<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée du module Dashboard : agrège les KPI de la société active.
 */
final class DashboardServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}
