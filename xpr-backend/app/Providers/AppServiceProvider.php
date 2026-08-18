<?php

namespace App\Providers;

use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;
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
        // ── Chasse aux N+1, par la contrainte plutôt que par la relecture ──
        //
        // `preventLazyLoading` fait ÉCHOUER l'accès à une relation non chargée.
        // C'est la seule façon durable de tenir la règle : relire les
        // contrôleurs à la main trouve les N+1 du jour, pas ceux que la
        // prochaine Resource introduira — un `$this->partner->legal_name`
        // ajouté dans six mois passe la revue et coûte une requête par ligne.
        //
        // HORS PRODUCTION uniquement : en production, un lazy load oublié doit
        // rendre la page lentement, jamais la casser. C'est le réglage
        // recommandé par Laravel, et il suppose que la CI, elle, tourne bien
        // en non-production — ce qui est le cas ici (`APP_ENV=testing`).
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
