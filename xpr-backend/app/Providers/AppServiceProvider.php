<?php

namespace App\Providers;

use App\Modules\Shared\Diagnostics\QueryTrail;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Un seul état de tenant par requête/job : tout le monde (scope,
        // middleware, jobs) doit voir la même société active.
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // INSTRUMENTATION TEMPORAIRE (cf. QueryTrail et RegisterController).
        // Couvre TOUTE la requête : la session, le rate limiter et la validation
        // interrogent la base bien avant le contrôleur, et la requête qui avorte
        // la transaction peut être l'une d'elles.
        //
        // Sur l'événement plutôt que sur DB::connection() : appeler le manager
        // ici forcerait l'ouverture d'une connexion PostgreSQL à CHAQUE
        // démarrage, y compris pour /up ou une commande artisan qui n'en a pas
        // besoin. En serverless, où chaque invocation repart à froid, cela se
        // paierait sur toutes les requêtes.
        Event::listen(static function (ConnectionEstablished $event): void {
            $event->connection->beforeExecuting(
                static fn (string $query): mixed => QueryTrail::attempt($query),
            );
        });

        DB::listen(static fn (QueryExecuted $query): mixed => QueryTrail::succeed($query->sql));
    }
}
