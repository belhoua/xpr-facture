<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Authentication\Models\User;
use App\Modules\Tenancy\Enums\LegalForm;
use App\Modules\Tenancy\Enums\Role;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CompanyProvisioning;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Société par défaut + son compte propriétaire.
 *
 * ── Pourquoi ce seeder existe ──────────────────────────────────────────────
 *
 * Une base fraîchement migrée ne contient AUCUN compte : personne ne peut se
 * connecter, donc toute requête de l'espace applicatif repart en 401, et le
 * front — qui n'a pas de porte de sortie pour ce cas — affiche « Une erreur est
 * survenue » sur chaque écran. Le symptôme ressemble à une panne générale ; la
 * cause est qu'il n'y a simplement personne à qui ouvrir.
 *
 * Le compte créé ici n'est PAS une donnée de démonstration : c'est
 * l'utilisateur réel de l'exploitant, sa société réelle, et rien d'autre —
 * aucun tiers, aucun article, aucun document. Le livre reste vierge.
 *
 * ── Le garde-fou de production ─────────────────────────────────────────────
 *
 * Sans `XPR_ADMIN_PASSWORD`, ce seeder s'abstient en production. Un mot de
 * passe par défaut lu dans un fichier versionné serait le même sur tous les
 * déploiements, et le compte qu'il ouvre est propriétaire — soit la totalité
 * des permissions. Mieux vaut une base sans compte, qu'on amorce sciemment par
 * `xpr:create-admin`, qu'un compte owner dont le mot de passe est public.
 *
 * ── Idempotence ───────────────────────────────────────────────────────────
 *
 * Rejouable : un e-mail déjà connu n'est jamais recréé ni réinitialisé, et une
 * société déjà rattachée n'est pas dupliquée. `db:seed` sur une base en service
 * ne touche donc à rien. Le mot de passe d'un compte existant est laissé tel
 * quel — le reprendre depuis l'environnement écraserait silencieusement celui
 * que l'utilisateur a changé lui-même.
 */
final class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // Les suites de tests montent leurs propres comptes : une société
        // préexistante fausserait toute assertion de dénombrement, et les
        // tests d'isolation tenant en particulier.
        if ($this->container->environment('testing')) {
            return;
        }

        $password = $this->resolvePassword();

        if ($password === null) {
            return;
        }

        /** @var string $email */
        $email = config('xpr.admin.email');
        $email = mb_strtolower($email);

        /** @var string $legalFormValue */
        $legalFormValue = config('xpr.admin.legal_form');
        $legalForm = LegalForm::tryFrom($legalFormValue);

        if (! $legalForm instanceof LegalForm) {
            $this->report("XPR_ADMIN_LEGAL_FORM inconnu : {$legalFormValue}", 'error');

            return;
        }

        try {
            $company = DB::transaction(
                fn (): Company => $this->provision($email, $password, $legalForm),
            );
        } catch (Throwable $exception) {
            // Un seed qui explose laisse une base à demi peuplée sans le dire.
            // On signale et on rend la main : les référentiels, eux, sont déjà
            // en place et suffisent à faire tourner les migrations suivantes.
            $this->report('AdminSeeder : '.$exception->getMessage(), 'error');

            return;
        }

        $this->report("Compte propriétaire prêt : {$email} — société « {$company->legal_name} »");
    }

    /**
     * Écrit sur la console quand il y en a une.
     *
     * `Seeder::$command` est documenté non-nullable par Laravel alors qu'il ne
     * l'est pas : un seeder appelé hors console — depuis un test, un tinker,
     * un autre seeder monté à la main — n'en a aucun. On vérifie donc l'objet
     * plutôt que de croire l'annotation, que PHPStan prend au mot (`?->` y est
     * signalé comme inutile, et `->` y planterait pour de vrai).
     */
    private function report(string $message, string $level = 'info'): void
    {
        // Le PHPDoc du framework annonce un Command garanti ; à l'exécution, la
        // propriété est nulle dès que le seeder tourne hors console. On garde
        // la vérification, pas l'annotation.
        // @phpstan-ignore instanceof.alwaysTrue
        if (! $this->command instanceof Command) {
            return;
        }

        match ($level) {
            'warn' => $this->command->warn($message),
            'error' => $this->command->error($message),
            default => $this->command->info($message),
        };
    }

    /**
     * Mot de passe d'amorçage, ou null s'il ne faut rien créer.
     *
     * Hors production, un défaut connu est un confort assumé : la base est
     * jetable et se reconstruit à la demande.
     */
    private function resolvePassword(): ?string
    {
        /** @var string|null $configured */
        $configured = config('xpr.admin.password');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if ($this->container->environment('production')) {
            $this->report(
                'AdminSeeder ignoré : définissez XPR_ADMIN_PASSWORD, ou amorcez le compte '
                .'avec `php artisan xpr:create-admin` (saisie masquée).',
                'warn',
            );

            return null;
        }

        return 'password';
    }

    private function provision(string $email, string $password, LegalForm $legalForm): Company
    {
        /** @var string $companyName */
        $companyName = config('xpr.admin.company');

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $user = User::query()->create([
                'name' => config('xpr.admin.name'),
                'email' => $email,
                // Le cast `hashed` du modèle chiffre à l'écriture : hacher ici
                // produirait un hash de hash, et la connexion échouerait.
                'password' => $password,
                'locale' => 'fr',
            ]);

            // Personne ne relèvera la boîte d'un compte d'amorçage, et un
            // e-mail non vérifié bloque l'accès à l'espace applicatif.
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $existing = $user->companies()->first();

        if ($existing instanceof Company) {
            // Déjà rattaché : on garantit seulement le rôle, sans rien recréer.
            $this->ensureRole($user, $existing);
            $this->fillLegalIdentity($existing);

            return $existing;
        }

        // Passe par le provisioning de l'inscription publique, jamais par des
        // écritures maison : c'est lui qui pose le rôle owner, ouvre l'exercice
        // comptable et crée les séquences de numérotation. Les réécrire ici
        // ferait diverger l'amorçage de la porte principale au premier
        // changement — exactement ce que `xpr:create-admin` évite déjà.
        $company = app(CompanyProvisioning::class)->createFirstCompanyFor($user, $companyName, $legalForm);

        // `createFirstCompanyFor` pose `owner` — c'est la règle de l'inscription
        // publique, où celui qui crée la société la possède. On réaligne ensuite
        // sur le rôle configuré, sans dupliquer ici l'ouverture de l'exercice ni
        // la création des séquences.
        $this->ensureRole($user, $company);

        $this->fillLegalIdentity($company);

        return $company;
    }

    /**
     * Mentions légales de l'exploitant sur la société d'amorçage.
     *
     * Sans elles, la société existe mais ne peut émettre AUCUN document
     * conforme : ICE, IF, RC et patente sont obligatoires au pied de chaque
     * facture (§3), et le gabarit d'impression les lit sur la société active —
     * un champ vide n'imprime rien, en silence.
     *
     * La migration `2026_08_15_000001_fill_bcat_legal_identity` porte les mêmes
     * valeurs, et ce doublon est délibéré : elle traite les bases DÉJÀ peuplées,
     * où la société précède le correctif, tandis qu'ici la société est créée
     * APRÈS que les migrations ont toutes tourné — la migration n'a alors
     * personne à compléter. Une migration ne doit par ailleurs jamais dépendre
     * d'une constante applicative, qui changerait sous elle et réécrirait
     * l'histoire à chaque relecture.
     *
     * Ciblage par le nom, comme la migration : ces coordonnées appartiennent à
     * BCAT. Renommer XPR_ADMIN_COMPANY crée une société vierge de mentions,
     * jamais une société portant l'ICE de quelqu'un d'autre.
     */
    private function fillLegalIdentity(Company $company): void
    {
        if (! str_starts_with(mb_strtolower($company->legal_name), 'bcat')) {
            return;
        }

        $identity = [
            'ice' => '002091111000017',
            'if_number' => '26066474',
            'rc_number' => '32577',
            'rc_city' => 'Oujda',
            'patente' => '10100485',
            'cnss' => '1144864',
            'address' => '8 BD Moulay Ahmed Lagrari 6ème Étage App N°25',
            'city' => 'OUJDA 60000',
            'phone' => '0536686883 / 0661940997',
            'email' => 'bcatcontrol@gmail.com',
            'bank_rib' => '011640000032210000180410',
        ];

        // Ne comble que les vides : une valeur saisie depuis l'écran des
        // paramètres est le fait d'un utilisateur qui savait ce qu'il écrivait.
        // L'écraser depuis un seeder reviendrait à décider à sa place, sur des
        // mentions fiscales — et `db:seed` est rejoué bien plus souvent qu'on
        // ne le croit.
        $missing = array_filter(
            $identity,
            static fn (string $column): bool => in_array(
                $company->getAttribute($column), [null, ''], strict: true,
            ),
            ARRAY_FILTER_USE_KEY,
        );

        if ($missing === []) {
            return;
        }

        $company->forceFill($missing)->save();
    }

    /**
     * Rôle du compte d'amorçage, scopé à la société (mode teams Spatie) : sans
     * ce périmètre, l'attribution s'écrirait sur le périmètre null, que le
     * registre n'interroge jamais pour une requête tenant.
     */
    private function ensureRole(User $user, Company $company): void
    {
        $role = $this->configuredRole();

        $permissions = app(PermissionRegistrar::class);
        $previous = $permissions->getPermissionsTeamId();
        $permissions->setPermissionsTeamId($company->id);

        try {
            // `syncRoles` et non `assignRole` : le compte d'amorçage doit finir
            // avec EXACTEMENT le rôle configuré. Ajouter sans retirer laisserait
            // cumuler `owner` et `admin` après un changement de configuration,
            // et le plus permissif des deux l'emporterait en silence.
            if (! $user->hasRole($role->value)) {
                $user->syncRoles([$role->value]);
            }
        } finally {
            $permissions->setPermissionsTeamId($previous);
        }
    }

    /**
     * Rôle du compte d'amorçage, `owner` à défaut.
     *
     * Une valeur inconnue n'est pas ignorée : la retomber silencieusement sur
     * `owner` donnerait tous les droits à un compte qu'on voulait restreindre.
     * On signale et on s'abstient — le compte reste alors sans rôle, ce que
     * l'écran des utilisateurs montre, plutôt que de trancher à la place de
     * l'exploitant.
     */
    private function configuredRole(): Role
    {
        /** @var string $configured */
        $configured = config('xpr.admin.role', Role::Owner->value);

        $role = Role::tryFrom($configured);

        if (! $role instanceof Role) {
            $this->report("XPR_ADMIN_ROLE inconnu : {$configured} — repli sur owner.", 'warn');

            return Role::Owner;
        }

        return $role;
    }
}
