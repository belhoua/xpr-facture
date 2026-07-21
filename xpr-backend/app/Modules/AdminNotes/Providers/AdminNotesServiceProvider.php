<?php

declare(strict_types=1);

namespace App\Modules\AdminNotes\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée du module AdminNotes : notes/tickets adressés au support.
 */
final class AdminNotesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');

        // Limite d'ouverture de tickets, par utilisateur (§10) : contient un
        // compte compromis ou un formulaire en boucle sans gêner un usage réel.
        RateLimiter::for('admin-notes', function (Request $request): Limit {
            return Limit::perHour(30)->by(
                (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
            );
        });
    }
}
