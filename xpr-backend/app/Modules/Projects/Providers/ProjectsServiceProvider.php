<?php

declare(strict_types=1);

namespace App\Modules\Projects\Providers;

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
    }
}
