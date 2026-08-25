<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Providers;

use App\Modules\Tenancy\Console\CreateAdminCommand;
use App\Modules\Tenancy\Console\PurgeDataCommand;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée du module Tenancy : gestion des membres de la société active.
 */
final class TenancyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');

        // Déclarées ici et non dans `app/Console/Commands` : le dépôt est
        // découpé par domaine métier (§15), et amorcer un propriétaire — comme
        // remettre son espace de travail à blanc — relève du tenancy.
        // Enregistrées seulement en CLI : une commande n'a rien à faire dans le
        // conteneur d'une requête HTTP, la purge moins que toute autre.
        if ($this->app->runningInConsole()) {
            $this->commands([CreateAdminCommand::class, PurgeDataCommand::class]);
        }

        // Limite par société ET par utilisateur (§10) : une invitation en
        // masse depuis un compte compromis reste contenue.
        RateLimiter::for('invitations', function (Request $request): Limit {
            return Limit::perHour(20)->by(
                (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
            );
        });
    }
}
