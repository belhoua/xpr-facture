<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Authentication\Models\User;
use App\Modules\Tenancy\Enums\LegalForm;
use App\Modules\Tenancy\Enums\Role;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CompanyProvisioning;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crée un compte propriétaire et sa société, sans passer par l'inscription
 * publique.
 *
 * Raison d'être : la fonction serverless n'expose pas artisan (cf.
 * `docs/architecture/06-deploiement-serverless.md` §7), et l'amorçage d'un
 * environnement se fait donc depuis un poste local pointé sur la base
 * distante — exactement comme les migrations et les seeders de référentiel.
 * C'est aussi la porte de secours quand l'inscription publique est en panne.
 *
 * Le mot de passe est demandé en SAISIE MASQUÉE par défaut : passé en option,
 * il se retrouve dans l'historique du shell et dans la table des processus.
 * L'option `--password` existe malgré tout, parce qu'un shell non interactif
 * (conteneur, CI, `docker compose exec -T`) ne peut répondre à aucune invite —
 * elle avertit à chaque usage plutôt que de faire semblant d'être sûre.
 *
 * Idempotence : un e-mail déjà connu n'est pas recréé. La commande PROMEUT
 * alors le compte existant (rattachement à la société et rôle owner) plutôt que
 * d'échouer — c'est le cas courant quand l'inscription a laissé un utilisateur
 * sans société derrière elle.
 */
final class CreateAdminCommand extends Command
{
    protected $signature = 'xpr:create-admin
        {--email= : Adresse e-mail du compte}
        {--name= : Nom affiché}
        {--company= : Raison sociale de la société à créer}
        {--legal-form=sarl : Forme juridique (auto_entrepreneur, sarl, sarl_au, sa, sas, snc, cooperative)}
        {--password= : DÉCONSEILLÉ — saisie masquée par défaut (voir le docblock)}';

    protected $description = 'Crée (ou promeut) un compte propriétaire et sa société';

    public function handle(CompanyProvisioning $provisioning, PermissionRegistrar $permissions): int
    {
        $email = $this->stringOption('email') ?? $this->ask('Adresse e-mail');
        $name = $this->stringOption('name') ?? $this->ask('Nom affiché');
        $companyName = $this->stringOption('company') ?? $this->ask('Raison sociale');
        $legalFormValue = $this->stringOption('legal-form') ?? LegalForm::Sarl->value;

        $legalForm = LegalForm::tryFrom($legalFormValue);

        if (! $legalForm instanceof LegalForm) {
            $this->error("Forme juridique inconnue : {$legalFormValue}");

            return self::FAILURE;
        }

        $password = $this->stringOption('password');

        if ($password === null) {
            $password = $this->secret('Mot de passe');

            if ($password !== $this->secret('Confirmer le mot de passe')) {
                $this->error('Les deux saisies diffèrent.');

                return self::FAILURE;
            }
        } else {
            // L'option existe parce qu'un shell non interactif (conteneur,
            // CI, `exec -T`) ne peut pas répondre à une invite. Elle reste
            // déconseillée : le mot de passe atterrit dans l'historique du
            // shell et dans la table des processus. Averti à chaque usage —
            // un avertissement qu'on ne voit pas ne protège personne.
            $this->warn('Mot de passe passé en option : pensez à purger l\'historique du shell.');
        }

        // La casse du domaine n'est pas signifiante en SMTP, mais la recherche
        // du compte à la connexion est un `where('email', …)` exact : saisir
        // « chakib@BCAT.com » ici et « chakib@bcat.com » au login créerait un
        // compte introuvable par son propre titulaire.
        $email = mb_strtolower($email);

        // EXACTEMENT les règles de l'inscription publique (`RegisterRequest`) :
        // ni plus strictes, ni plus laxistes. Plus strictes, cette porte
        // refuserait un mot de passe que la porte principale accepte, ce qui
        // n'a aucun sens ; plus laxistes, elle deviendrait le maillon faible.
        // Si la politique doit durcir, elle durcit aux deux endroits.
        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'company' => $companyName, 'password' => $password],
            [
                'email' => ['required', 'email:rfc', 'max:255'],
                'name' => ['required', 'string', 'min:2', 'max:255'],
                'company' => ['required', 'string', 'min:2', 'max:255'],
                'password' => ['required', 'string', Password::min(8)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        // Une seule transaction, comme l'inscription : un compte créé sans
        // société ni séquences serait inutilisable, et c'est précisément l'état
        // à demi-provisionné qu'on vient réparer.
        try {
            $company = DB::transaction(function () use (
                $provisioning,
                $permissions,
                $email,
                $name,
                $password,
                $companyName,
                $legalForm,
            ): Company {
                $user = User::query()->where('email', $email)->first();

                if ($user instanceof User) {
                    $this->line("Compte existant réutilisé : {$email}");

                    // Le mot de passe saisi fait foi : la commande sert aussi à
                    // reprendre la main sur un compte dont on a perdu l'accès.
                    $user->forceFill(['password' => $password])->save();
                } else {
                    $user = User::query()->create([
                        'name' => $name,
                        'email' => $email,
                        'password' => $password,
                        'locale' => 'fr',
                    ]);
                }

                // Vérifié d'office : personne ne relèvera la boîte d'un compte
                // d'amorçage, et un e-mail non vérifié bloquerait l'accès.
                $user->forceFill(['email_verified_at' => now()])->save();

                $existing = $user->companies()->first();

                if ($existing instanceof Company) {
                    // Le compte a déjà une société : on ne lui en crée pas une
                    // seconde, on s'assure seulement qu'il y est bien owner.
                    $this->line("Société existante réutilisée : {$existing->legal_name}");

                    $this->promoteToOwner($permissions, $user, $existing);

                    return $existing;
                }

                // Passe par le provisioning de l'inscription, jamais par des
                // écritures maison : c'est lui qui pose le rôle owner, ouvre
                // l'exercice comptable et crée les séquences. Les dupliquer ici
                // ferait diverger la porte de service de la porte principale au
                // premier changement.
                return $provisioning->createFirstCompanyFor($user, $companyName, $legalForm);
            });
        } catch (\Throwable $exception) {
            $this->error('Échec : '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('✅ Compte propriétaire prêt.');
        $this->table(
            ['Champ', 'Valeur'],
            [
                ['E-mail', $email],
                ['Société', $company->legal_name],
                ['Rôle', Role::Owner->value],
                ['Identifiant société', $company->id],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Rattache le compte à la société et lui donne `owner`.
     *
     * Le rôle est scopé à la société (mode teams de Spatie) : sans
     * `setPermissionsTeamId`, le registre interroge le périmètre `null` et
     * l'attribution ne vaudrait dans aucune société (CLAUDE.md §15).
     */
    private function promoteToOwner(
        PermissionRegistrar $permissions,
        User $user,
        Company $company,
    ): void {
        if (! $user->companies()->whereKey($company->id)->exists()) {
            $user->companies()->attach($company->id, ['joined_at' => now()]);
        }

        $previousTeamId = $permissions->getPermissionsTeamId();
        $permissions->setPermissionsTeamId($company->id);

        try {
            $user->assignRole(Role::Owner->value);
        } finally {
            $permissions->setPermissionsTeamId($previousTeamId);
        }

        $user->forceFill(['default_company_id' => $company->id])->save();
    }

    /** Les options d'artisan sont typées `mixed` : on n'en accepte que des chaînes non vides. */
    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
