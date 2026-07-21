<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Providers;

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

        // Limite par société ET par utilisateur (§10) : une invitation en
        // masse depuis un compte compromis reste contenue.
        RateLimiter::for('invitations', function (Request $request): Limit {
            return Limit::perHour(20)->by(
                (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
            );
        });
    }
}
