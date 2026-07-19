<?php

namespace App\Providers;

use App\Modules\Tenancy\Services\TenantContext;
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
        //
    }
}
