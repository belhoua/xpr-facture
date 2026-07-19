<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Middleware;

use App\Modules\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Résout la société active depuis l'utilisateur authentifié et propage le
 * contexte (scope Eloquent + GUC PostgreSQL pour la RLS). À placer après
 * auth:sanctum sur toutes les routes tenant.
 */
final class SetTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $this->context->authenticateUser((string) $user->getKey());

            $company = $user->resolveActiveCompany();

            if ($company !== null) {
                $this->context->activateCompany((string) $company->getKey());
            }
        }

        return $next($request);
    }

    /** Un état de tenant ne doit jamais survivre à sa requête. */
    public function terminate(): void
    {
        $this->context->forget();
    }
}
