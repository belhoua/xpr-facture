<?php

declare(strict_types=1);

namespace App\Modules\Cash\Providers;

use App\Modules\Cash\Console\BackfillCashMirrorCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée du module Cash : expose le suivi des flux de trésorerie.
 */
final class CashServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');

        // Reprise et réparation du miroir des règlements. Enregistrée seulement
        // en CLI : une commande n'a rien à faire dans le conteneur d'une
        // requête HTTP.
        if ($this->app->runningInConsole()) {
            $this->commands([BackfillCashMirrorCommand::class]);
        }
    }
}
