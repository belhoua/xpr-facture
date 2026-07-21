<?php

declare(strict_types=1);

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
 * société fraîche, avec le jeu de données de démonstration (7 factures dont
 * 1 brouillon, 5 mouvements de caisse). Partagé par toutes les suites Workspace
 * — défini ici pour rester disponible même lors d'un run ciblé sur un fichier.
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

    app(WorkspaceDemoDataService::class)->seedForCompany($company);

    return [$user, $company];
}
