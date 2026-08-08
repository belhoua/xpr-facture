<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\TaxRate;
use App\Modules\Accounting\Services\CompanyAccountingProvisioning;
use App\Modules\Authentication\Models\User;
use App\Modules\Catalog\Services\CompanyCatalogProvisioning;
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
    // Même ordre qu'en production (cf. CompanyProvisioning) : la nomenclature
    // de services d'abord, le jeu de démonstration s'y raccroche ensuite.
    app(CompanyCatalogProvisioning::class)->initialize($company);
    app(WorkspaceDemoDataService::class)->seedForCompany($company);

    return [$user, $company];
}

/**
 * Colonnes minimales d'une convention, écrites DIRECTEMENT en base.
 *
 * Ici et non dans un fichier de test : deux suites s'en servent
 * (`ConventionTest`, `FileDepositTest`) et Pest n'inclut un fichier qu'au moment
 * de l'exécuter — une fonction définie dans l'un manquerait à l'autre selon
 * l'ordre de passage.
 *
 * Pas de factory : le module n'en expose pas, et les tests qui en ont besoin
 * fabriquent une convention de CONTRÔLE, pas un jeu de données réaliste. Le
 * `company_id` est explicite — plusieurs cas insèrent délibérément dans une
 * société qui n'est pas celle du contexte, pour éprouver le cloisonnement.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function conventionColumns(string $companyId, array $overrides = []): array
{
    return array_merge([
        'company_id' => $companyId,
        'owner_name' => 'Société Clinique La Vallée',
        'project_description' => 'Construction d\'une polyclinique',
        'total_cents' => 16_224_000,
    ], $overrides);
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
