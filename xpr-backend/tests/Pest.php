<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\TaxRate;
use App\Modules\Accounting\Services\CompanyAccountingProvisioning;
use App\Modules\Authentication\Models\User;
use App\Modules\Shared\Services\WorkspaceDemoDataService;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * Fabrique un compte prêt à l'emploi : un utilisateur owner rattaché à une
 * société fraîche, avec le jeu de données de démonstration — catalogue de
 * 7 articles, 7 factures (dont 1 brouillon), 1 devis accepté, 5 mouvements de
 * caisse. Partagé par toutes les suites Workspace : défini ici pour rester
 * disponible même lors d'un run ciblé sur un fichier.
 *
 * @return array{0: User, 1: Company}
 */
function workspaceAccount(): array
{
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $user->companies()->attach($company, ['joined_at' => now()]);
    $user->forceFill(['default_company_id' => $company->id])->save();

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $user->assignRole('owner');

    // Exercice + séquences AVANT les données : les documents de démo sont
    // numérotés par la séquence, comme de vrais documents émis.
    app(CompanyAccountingProvisioning::class)->initialize($company);
    app(WorkspaceDemoDataService::class)->seedForCompany($company);

    return [$user, $company];
}

/**
 * Identifiant d'un taux du catalogue STANDARD marocain (company_id NULL),
 * partagé par toutes les sociétés.
 *
 * Résolu plutôt qu'écrit en dur : les identifiants sont des UUID générés au
 * seed, et §3 interdit de coder un taux en dur — y compris dans un test, qui
 * doit rester valide le jour où la DGI en change un.
 */
function taxRateId(string $rate): string
{
    /** @var TaxRate $model */
    $model = TaxRate::query()
        ->whereNull('company_id')
        ->where('kind', 'standard')
        ->where('rate', $rate)
        ->firstOrFail();

    return $model->id;
}
