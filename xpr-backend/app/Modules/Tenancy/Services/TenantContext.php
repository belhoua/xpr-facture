<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Exceptions\TenantContextMissing;
use Illuminate\Support\Facades\DB;

/**
 * Source de vérité de la société active pour la durée d'une requête HTTP ou
 * d'un job. Résolue depuis l'utilisateur authentifié — jamais depuis un
 * paramètre de requête (CLAUDE.md §5.3) — puis propagée à PostgreSQL pour
 * activer la Row Level Security.
 *
 * Singleton de conteneur : un état par requête (php-fpm) ; à revalider si
 * passage à Octane (un reset par requête serait requis).
 */
final class TenantContext
{
    private ?string $companyId = null;

    private ?string $userId = null;

    /**
     * set_config(..., false) = portée session. Sûr ici : php-fpm tient une
     * connexion par requête, libérée à la fin. Si un pooler en mode
     * transaction (PgBouncer) entre dans la stack, cette propagation doit
     * devenir SET LOCAL dans une transaction par requête — décision notée
     * dans docs/architecture/00-critique.md §1.4.
     */
    public function authenticateUser(string $userId): void
    {
        $this->userId = $userId;
        DB::statement('SELECT set_config(?, ?, false)', ['app.user_id', $userId]);
    }

    public function activateCompany(string $companyId): void
    {
        $this->companyId = $companyId;
        DB::statement('SELECT set_config(?, ?, false)', ['app.company_id', $companyId]);
    }

    public function currentId(): ?string
    {
        return $this->companyId;
    }

    /** Consommé par l'audit (P0-12) : qui agit, indépendamment de la société. */
    public function currentUserId(): ?string
    {
        return $this->userId;
    }

    public function requireId(): string
    {
        return $this->companyId ?? throw new TenantContextMissing;
    }

    /**
     * Nettoyage en fin de requête et entre deux jobs d'un même worker :
     * un worker Horizon réutilise sa connexion d'un job à l'autre, sans reset
     * le contexte du tenant précédent fuirait dans le job suivant.
     */
    public function forget(): void
    {
        $this->companyId = null;
        $this->userId = null;

        DB::statement("SELECT set_config('app.company_id', '', false)");
        DB::statement("SELECT set_config('app.user_id', '', false)");
    }
}
