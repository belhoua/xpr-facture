<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Middleware;

use App\Modules\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Résout la société active depuis l'utilisateur authentifié et propage le
 * contexte à ses TROIS consommateurs : le scope Eloquent, la GUC PostgreSQL
 * qui arme la RLS, et le registre de permissions Spatie (mode teams).
 *
 * À placer après auth:sanctum sur toutes les routes tenant.
 */
final class SetTenantContext
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PermissionRegistrar $permissions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $this->context->authenticateUser((string) $user->getKey());

            $company = $user->resolveActiveCompany();

            if ($company !== null) {
                $companyId = (string) $company->getKey();

                $this->context->activateCompany($companyId);

                // Spatie est en mode teams (team_foreign_key = company_id) :
                // sans ce réglage, le registre interroge le périmètre null et
                // ne trouve AUCUN rôle attribué. `hasRole()` et `can()`
                // répondraient faux pour tout le monde — ou pire, un rôle
                // détenu dans une autre société serait pris en compte.
                $this->permissions->setPermissionsTeamId($companyId);
            }
        }

        return $next($request);
    }

    /** Un état de tenant ne doit jamais survivre à sa requête. */
    public function terminate(): void
    {
        $this->context->forget();

        // Le registre est un singleton : un team id laissé en place fuiterait
        // sur la requête suivante servie par le même worker.
        $this->permissions->setPermissionsTeamId(null);
    }
}
