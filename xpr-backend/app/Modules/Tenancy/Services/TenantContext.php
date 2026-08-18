<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Exceptions\TenantContextMissing;
use App\Modules\Tenancy\Models\Company;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

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

    /**
     * Un utilisateur est authentifié, mais aucune société n'est active.
     *
     * Distingue les deux façons d'être « hors contexte », que `currentId()`
     * seul confond :
     *   - une requête HTTP d'un compte détaché de toute société → il ne doit
     *     RIEN voir (cf. le scope de BelongsToCompany) ;
     *   - un traitement hors requête (console, seeder, migration) → il doit
     *     tout voir, sans quoi plus aucune commande d'administration ne
     *     fonctionne.
     */
    public function hasUserWithoutCompany(): bool
    {
        return $this->userId !== null && $this->companyId === null;
    }

    /**
     * Exécute un traitement dans le contexte d'une société, puis restaure le
     * contexte précédent.
     *
     * Nécessaire aux initialisations qui surviennent AVANT que le middleware
     * n'ait activé une société — le provisioning à l'inscription, notamment :
     * sans `app.company_id`, la RLS refuse les INSERT au rôle `xpr_app` et le
     * trait BelongsToCompany lève TenantContextMissing.
     *
     * Ne touche pas à `app.user_id` : l'auteur de l'action reste le même.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runForCompany(string $companyId, Closure $callback): mixed
    {
        $previous = $this->companyId;
        $this->activateCompany($companyId);

        try {
            $result = $callback();
        } catch (Throwable $e) {
            // Restauration EN MÉMOIRE UNIQUEMENT, jamais par une requête.
            //
            // Si le callback a échoué à l'intérieur d'une transaction, PostgreSQL
            // l'a avortée : toute requête suivante est refusée avec 25P02
            // (« current transaction is aborted ») jusqu'au ROLLBACK. Un
            // set_config() de restauration lèverait donc une SECONDE exception —
            // et une exception levée pendant la propagation d'une autre la
            // REMPLACE, sans même la chaîner en previous. La cause réelle de
            // l'échec était détruite, et l'appelant ne recevait que 25P02 : le
            // symptôme du nettoyage, jamais la panne d'origine.
            //
            // Ne rien envoyer à PostgreSQL ici ne laisse aucun état résiduel :
            // set_config(..., false) est un SET de session, que le ROLLBACK
            // annule avec le reste de la transaction. Hors transaction, la
            // requête suivante repose la GUC avant de lire quoi que ce soit.
            $this->companyId = $previous;

            throw $e;
        }

        $previous === null
            ? $this->releaseCompany()
            : $this->activateCompany($previous);

        return $result;
    }

    /**
     * Chaîne vide et NON NULL : les policies RLS lisent la GUC via
     * NULLIF(current_setting(...), '')::uuid — un `''::uuid` casserait
     * toute requête ultérieure de la connexion.
     */
    private function releaseCompany(): void
    {
        $this->companyId = null;
        DB::statement("SELECT set_config('app.company_id', '', false)");
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

    public function requireCompany(): Company
    {
        return Company::query()->findOrFail($this->requireId());
    }

    /**
     * Nettoyage en fin de requête et entre deux jobs d'un même worker :
     * un worker Horizon réutilise sa connexion d'un job à l'autre, sans reset
     * le contexte du tenant précédent fuirait dans le job suivant.
     */
    public function forget(): void
    {
        $this->forgetInMemory();

        DB::statement("SELECT set_config('app.company_id', '', false)");
        DB::statement("SELECT set_config('app.user_id', '', false)");
    }

    /**
     * Volet PHP de forget(), sans toucher à PostgreSQL.
     *
     * Utile sur un chemin d'erreur, où la transaction peut être avortée : une
     * requête y lèverait 25P02, qui remplacerait la cause de l'échec. L'appelant
     * reste responsable de l'état côté base — en jetant la connexion s'il ne
     * peut pas la nettoyer (cf. Jobs/Middleware/TenantAware).
     */
    public function forgetInMemory(): void
    {
        $this->companyId = null;
        $this->userId = null;
    }
}
