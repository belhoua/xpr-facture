<?php

declare(strict_types=1);

namespace App\Modules\Projects\Providers;

use App\Modules\Projects\Console\BackfillQuoteProjectsCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée du module Avancement de projet : suivi des missions menées
 * pour un client, et livrables qu'on lui a remis.
 */
final class ProjectsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');

        // Reprise de données, à lancer une fois après la levée du 2026-08-25.
        // Enregistrée seulement en CLI : une commande n'a rien à faire dans le
        // conteneur d'une requête HTTP.
        if ($this->app->runningInConsole()) {
            $this->commands([BackfillQuoteProjectsCommand::class]);
        }
    }
}
