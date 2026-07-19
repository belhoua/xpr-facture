<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Jobs\Middleware;

use App\Modules\Tenancy\Services\TenantContext;
use Closure;

/**
 * Middleware de job : restaure le contexte tenant depuis le payload.
 * Un worker Horizon n'a pas d'utilisateur authentifié — chaque job tenant
 * DOIT porter une propriété publique $companyId (sérialisée avec le job)
 * et déclarer ce middleware, sinon scope et RLS le rendent aveugle.
 *
 * Le finally est essentiel : le worker réutilise sa connexion PostgreSQL
 * d'un job à l'autre ; sans reset, le tenant fuirait dans le job suivant.
 */
final class TenantAware
{
    public function handle(object $job, Closure $next): mixed
    {
        $context = app(TenantContext::class);

        try {
            if (property_exists($job, 'companyId') && is_string($job->companyId)) {
                $context->activateCompany($job->companyId);
            }

            return $next($job);
        } finally {
            $context->forget();
        }
    }
}
