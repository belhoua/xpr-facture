<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Shared\Services\WorkspaceDemoDataService;
use App\Modules\Tenancy\Enums\LegalForm;
use App\Modules\Tenancy\Models\Company;
use Spatie\Permission\PermissionRegistrar;

/**
 * Point d'extension unique de la création d'une société : appartenance,
 * rôle owner, et — au fil des modules à venir — taux de TVA, exercice
 * fiscal, séquences, moyens de paiement. Toute nouvelle initialisation de
 * société s'ajoute ICI, jamais dans un contrôleur.
 *
 * Doit être appelé dans une transaction ouverte par l'appelant : la création
 * du compte et celle de la société réussissent ou échouent ensemble.
 */
final class CompanyProvisioning
{
    public function __construct(
        private readonly PermissionRegistrar $permissions,
        private readonly WorkspaceDemoDataService $demoData,
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

        $this->demoData->seedForCompany($company);

        return $company;
    }
}
