<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Jobs\Middleware;

use App\Modules\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

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

            $result = $next($job);
        } catch (Throwable $e) {
            // Même piège que TenantContext::runForCompany() : forget() émet deux
            // requêtes, et sur une transaction avortée elles lèvent 25P02, qui
            // REMPLACERAIT la cause de l'échec du job. Le worker retenterait
            // alors sans jamais savoir pourquoi.
            //
            // La différence avec runForCompany() : ici on ne PEUT pas se
            // contenter de l'état mémoire. Le worker réutilise sa connexion
            // d'un job à l'autre ; une GUC app.company_id restée posée ferait
            // fuiter le tenant dans le job suivant. Si le nettoyage échoue, on
            // jette donc la connexion — la suivante repartira vierge.
            $context->forgetInMemory();

            try {
                $context->forget();
            } catch (Throwable) {
                DB::disconnect();
            }

            throw $e;
        }

        $context->forget();

        return $result;
    }
}
