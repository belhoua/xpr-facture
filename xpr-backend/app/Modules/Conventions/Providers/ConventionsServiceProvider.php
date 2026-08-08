<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée du module Conventions : contrats de convention de contrôle et
 * suivi, et dépôts de dossier auprès des organismes instructeurs.
 */
final class ConventionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}
