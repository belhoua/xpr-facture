<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Accounting\Services\CompanyAccountingProvisioning;
use App\Modules\Authentication\Models\User;
use App\Modules\Shared\Services\WorkspaceDemoDataService;
use App\Modules\Tenancy\Enums\LegalForm;
use App\Modules\Tenancy\Models\Company;
use Spatie\Permission\PermissionRegistrar;

/**
 * Point d'extension unique de la création d'une société : appartenance,
 * rôle owner, exercice comptable et séquences, et — au fil des modules à
 * venir — moyens de paiement. Toute nouvelle initialisation de société
 * s'ajoute ICI, jamais dans un contrôleur.
 *
 * Doit être appelé dans une transaction ouverte par l'appelant : la création
 * du compte et celle de la société réussissent ou échouent ensemble.
 */
final class CompanyProvisioning
{
    public function __construct(
        private readonly PermissionRegistrar $permissions,
        private readonly WorkspaceDemoDataService $demoData,
        private readonly CompanyAccountingProvisioning $accounting,
        private readonly TenantContext $tenant,
    ) {}

    public function createFirstCompanyFor(User $user, string $legalName, LegalForm $legalForm): Company
    {
        $company = Company::create([
            'legal_name' => $legalName,
            'legal_form' => $legalForm->value,
            // Règle métier : AE sous régime forfaitaire → TVA non applicable
            'vat_exempt' => $legalForm->defaultVatExempt(),
        ]);

        // Recharge les colonnes dont la valeur par défaut est posée par
        // PostgreSQL (default_currency = 'MAD', timezone) : l'instance issue
        // de create() ne les connaît pas, et un consommateur qui lirait
        // default_currency récupérerait null.
        $company->refresh();

        // La société existe : on bascule le contexte AVANT la première écriture
        // dans une table sous RLS.
        //
        // `company_user` en porte une (policy membership_visibility, WITH CHECK
        // sur app.company_id, sans tolérance NULL) : l'attach ci-dessous était
        // le tout premier INSERT du provisioning à la heurter, et il s'exécutait
        // hors contexte — celui-ci n'était posé que plus bas, par
        // accounting->initialize(). PostgreSQL refusait la ligne, avortait la
        // transaction, et tout le reste de l'inscription remontait en 25P02.
        //
        // Invisible en local : le rôle de développement est SUPERUSER, donc
        // exempt de FORCE ROW LEVEL SECURITY. Le défaut n'apparaît que sur une
        // base gérée, où l'application se connecte avec un rôle qui ne l'est
        // pas — c'est exactement l'angle mort décrit en CLAUDE.md §15.
        return $this->tenant->runForCompany($company->id, function () use ($user, $company): Company {
            $user->companies()->attach($company->id, ['joined_at' => now()]);

            // Le rôle est scopé à la société (mode teams de Spatie) : owner ICI
            // ne donne aucun droit dans une autre société.
            $previousTeamId = $this->permissions->getPermissionsTeamId();
            $this->permissions->setPermissionsTeamId($company->id);

            try {
                $user->assignRole('owner');
            } finally {
                $this->permissions->setPermissionsTeamId($previousTeamId);
            }

            $user->forceFill(['default_company_id' => $company->id])->save();

            // Exercice courant + séquences : sans eux, aucune facture ne peut
            // être validée. À faire avant tout jeu de données, qui numérote déjà.
            $this->accounting->initialize($company);

            // Le jeu de démonstration émet de vrais documents : une centaine de
            // requêtes dans le chemin critique de l'inscription. Acceptable avec
            // la base sur le même réseau, coûteux dès que la latence entre en
            // jeu — d'où l'interrupteur (cf. config/xpr.php).
            if (config('xpr.demo_data_on_signup') === true) {
                $this->demoData->seedForCompany($company);
            }

            return $company;
        });
    }
}
