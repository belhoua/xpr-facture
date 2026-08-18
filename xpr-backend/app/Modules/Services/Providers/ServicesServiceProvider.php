<?php

declare(strict_types=1);

namespace App\Modules\Services\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée du référentiel des SERVICES : la nature des missions menées
 * par la société, qui classe les projets.
 *
 * Module distinct de `Catalog` à dessein : celui-ci tient les articles vendus
 * (prix, unité, TVA), celui-là un vocabulaire de classement sans dimension
 * tarifaire (cf. la migration `create_services_table`).
 */
final class ServicesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}
