<?php

declare(strict_types=1);

namespace App\Modules\Cash\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée du module Cash : expose le suivi des flux de trésorerie.
 */
final class CashServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}
