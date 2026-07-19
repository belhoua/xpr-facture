<?php

declare(strict_types=1);

namespace App\Modules\Authentication\Providers;

use App\Modules\Authentication\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

final class AuthenticationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');

        // Le lien de réinitialisation atterrit sur le FRONTEND (qui rappelle
        // ensuite POST /auth/reset-password), pas sur une page Blade.
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            /** @var User $notifiable */
            return config('app.frontend_url')
                .'/reset-password?token='.$token
                .'&email='.urlencode($notifiable->email);
        });

        // Limites par (e-mail, IP) : bloque la force brute ciblée sans
        // pénaliser les collègues derrière la même IP d'entreprise.
        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)
                ->by(Str::lower($request->string('email')->value()).'|'.$request->ip());
        });

        RateLimiter::for('register', function (Request $request): Limit {
            return Limit::perHour(10)->by((string) $request->ip());
        });

        RateLimiter::for('forgot-password', function (Request $request): Limit {
            return Limit::perHour(3)
                ->by(Str::lower($request->string('email')->value()).'|'.$request->ip());
        });
    }
}
