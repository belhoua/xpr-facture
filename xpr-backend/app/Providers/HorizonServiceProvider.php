<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        $this->gate();
    }

    /**
     * Restreint /horizon aux e-mails listés dans HORIZON_ADMIN_EMAILS
     * (séparés par des virgules). Vide par défaut : personne n'accède au
     * tableau de bord tant qu'il n'est pas explicitement renseigné — plus
     * sûr qu'un accès ouvert par oubli.
     */
    protected function gate(): void
    {
        $allowed = array_filter(array_map(
            'trim',
            explode(',', (string) env('HORIZON_ADMIN_EMAILS', ''))
        ));

        Gate::define('viewHorizon', fn ($user = null): bool => $user !== null
            && in_array($user->email, $allowed, true));
    }
}
