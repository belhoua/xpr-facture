<?php

declare(strict_types=1);

use App\Modules\Authentication\Models\User;
use App\Modules\Services\Models\Service;
use App\Modules\Tenancy\Enums\Role;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

/**
 * Référentiel `services` — MODULE DORMANT depuis le 2026-08-26.
 *
 * Ces endpoints n'ont plus de consommateur : le classement des projets passe
 * par le CATALOGUE (`products` de type « service »), la seule liste que l'écran
 * `/services` alimente réellement. Le doublon de nommage faisait que les deux
 * ne se rejoignaient jamais — cf. la migration
 * `point_project_service_to_catalog` et `tests/Feature/Projects/ProjectServiceLinkTest.php`.
 *
 * Ces tests sont conservés parce que le CODE l'est : le module reste chargé et
 * ses routes répondent, retirer leur couverture laisserait du code non testé
 * derrière une porte encore ouverte. Ils ont été SORTIS du fichier des projets,
 * où ils donnaient à croire que ce référentiel classait encore quelque chose.
 *
 * ⚠️ Ne pas rebrancher un écran ici : ce serait recréer le second référentiel,
 * et le déroulant du projet redeviendrait vide.
 */
it('liste les services de la société active, par ordre alphabétique', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    Service::factory()->create(['name' => 'Suivi de chantier']);
    Service::factory()->create(['name' => 'Assistance à maîtrise d\'ouvrage']);

    $names = array_column(
        actingAs($user)->getJson('/api/v1/services')->assertOk()->json('data'),
        'name',
    );

    expect($names)->toBe(['Assistance à maîtrise d\'ouvrage', 'Suivi de chantier']);
});

it('crée un service', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/services', ['name' => '  Diagnostic structure  '])
        ->assertCreated()
        // Les espaces de bord sont retirés : ils rendraient deux entrées
        // visuellement identiques et échapperaient à l'unicité.
        ->assertJsonPath('name', 'Diagnostic structure');
});

it('refuse deux services de même nom dans une société', function (string $second): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    Service::factory()->create(['name' => 'Diagnostic structure']);

    actingAs($user)
        ->postJson('/api/v1/services', ['name' => $second])
        ->assertStatus(422)
        ->assertJsonPath('errors.name.0', fn (string $m): bool => $m !== '');
})->with([
    'à l’identique' => 'Diagnostic structure',
    'casse différente' => 'diagnostic structure',
]);

it('isole le référentiel entre deux sociétés', function (): void {
    [$userA, $companyA] = workspaceAccount();
    [$userB, $companyB] = workspaceAccount();

    app(TenantContext::class)->activateCompany($companyA->id);
    Service::factory()->create(['name' => 'Service de A']);

    app(TenantContext::class)->activateCompany($companyB->id);
    Service::factory()->create(['name' => 'Service de B']);

    $namesOf = static fn ($user): array => array_column(
        actingAs($user)->getJson('/api/v1/services')->assertOk()->json('data'),
        'name',
    );

    expect($namesOf($userA))->toBe(['Service de A']);
    expect($namesOf($userB))->toBe(['Service de B']);

    // Le même nom reste libre dans l'autre société : l'unicité est PAR
    // société, comme l'index partiel.
    actingAs($userB)->postJson('/api/v1/services', ['name' => 'Service de A'])->assertCreated();
});

it('cantonne un lecteur à la consultation du référentiel', function (): void {
    // Rattachement écrit ICI et non via un helper d'un autre fichier : Pest
    // n'inclut un fichier de test qu'au moment de l'exécuter, et la fonction
    // manquerait sur un run ciblé (cf. la note de tests/Pest.php).
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $user->companies()->attach($company, ['joined_at' => now()]);
    $user->forceFill(['default_company_id' => $company->id])->save();

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $user->assignRole(Role::Viewer->value);

    actingAs($user)->getJson('/api/v1/services')->assertOk();
    actingAs($user)->postJson('/api/v1/services', ['name' => 'Interdit'])->assertForbidden();
});
