<?php

declare(strict_types=1);

use App\Modules\Authentication\Models\User;
use App\Modules\Partners\Models\Partner;
use App\Modules\Projects\Models\Project;
use App\Modules\Services\Models\Service;
use App\Modules\Tenancy\Enums\Role;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

/**
 * Référentiel des SERVICES et classement des projets.
 *
 * Deux règles y sont tenues : le classement est FACULTATIF — le référentiel
 * naît vide, exiger un service interdirait de créer le premier projet — et il
 * reste cloisonné par société comme toute donnée métier (§5).
 */

/**
 * Client + service de la société active, prêts à porter un projet.
 *
 * @return array{0: Partner, 1: Service}
 */
function projectFixtures(string $companyId): array
{
    app(TenantContext::class)->activateCompany($companyId);

    return [
        Partner::factory()->client()->create(['ice' => null]),
        Service::factory()->create(['name' => 'Contrôle technique de construction']),
    ];
}

it('crée un projet SANS service', function (): void {
    [$user, $company] = workspaceAccount();
    [$client] = projectFixtures($company->id);

    // Le cas nominal tant que le référentiel est vide : le champ est absent du
    // payload, et rien ne doit l'exiger.
    actingAs($user)
        ->postJson('/api/v1/projects', [
            'partnerId' => $client->id,
            'title' => 'Chantier sans classement',
        ])
        ->assertCreated()
        ->assertJsonPath('serviceId', null)
        ->assertJsonPath('serviceName', null);
});

it('accepte un service NUL explicitement', function (): void {
    [$user, $company] = workspaceAccount();
    [$client] = projectFixtures($company->id);

    // « Aucun » dans le déroulant : le formulaire envoie null, pas l'absence.
    actingAs($user)
        ->postJson('/api/v1/projects', [
            'partnerId' => $client->id,
            'title' => 'Chantier explicitement non classé',
            'serviceId' => null,
        ])
        ->assertCreated()
        ->assertJsonPath('serviceId', null);
});

it('classe un projet sous un service', function (): void {
    [$user, $company] = workspaceAccount();
    [$client, $service] = projectFixtures($company->id);

    actingAs($user)
        ->postJson('/api/v1/projects', [
            'partnerId' => $client->id,
            'title' => 'Chantier classé',
            'serviceId' => $service->id,
        ])
        ->assertCreated()
        ->assertJsonPath('serviceId', $service->id)
        ->assertJsonPath('serviceName', 'Contrôle technique de construction');
});

it('rend le nom du service sur chaque ligne de la LISTE', function (): void {
    [$user, $company] = workspaceAccount();
    [$client, $service] = projectFixtures($company->id);

    actingAs($user)->postJson('/api/v1/projects', [
        'partnerId' => $client->id,
        'title' => 'Chantier classé',
        'serviceId' => $service->id,
    ])->assertCreated();

    // La relation est chargée d'emblée (`with('service')`) : sans elle, la
    // colonne SERVICE ferait une requête par ligne — et `preventLazyLoading`
    // ferait tomber l'écran plutôt que de le ralentir.
    expect(Model::preventsLazyLoading())->toBeTrue();

    actingAs($user)
        ->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonPath('data.0.serviceName', 'Contrôle technique de construction');
});

it('déclasse un projet en transmettant null', function (): void {
    [$user, $company] = workspaceAccount();
    [$client, $service] = projectFixtures($company->id);

    $id = actingAs($user)->postJson('/api/v1/projects', [
        'partnerId' => $client->id,
        'title' => 'Chantier classé',
        'serviceId' => $service->id,
    ])->assertCreated()->json('id');

    actingAs($user)
        ->patchJson("/api/v1/projects/{$id}", ['serviceId' => null])
        ->assertOk()
        ->assertJsonPath('serviceId', null);
});

it('laisse le classement INTACT sur un PATCH qui ne le porte pas', function (): void {
    [$user, $company] = workspaceAccount();
    [$client, $service] = projectFixtures($company->id);

    $id = actingAs($user)->postJson('/api/v1/projects', [
        'partnerId' => $client->id,
        'title' => 'Chantier classé',
        'serviceId' => $service->id,
    ])->assertCreated()->json('id');

    // Pousser le seul avancement ne doit pas déclasser le projet.
    actingAs($user)
        ->patchJson("/api/v1/projects/{$id}", ['progressPercentage' => 40])
        ->assertOk()
        ->assertJsonPath('serviceId', $service->id)
        ->assertJsonPath('progressPercentage', 40);
});

it('refuse le service d une AUTRE société', function (): void {
    [, $companyA] = workspaceAccount();
    [$userB, $companyB] = workspaceAccount();

    [, $serviceOfA] = projectFixtures($companyA->id);
    [$clientOfB] = projectFixtures($companyB->id);

    // La règle `exists` est scopée sur `company_id` : le service de A est
    // introuvable pour B (§5.3).
    actingAs($userB)
        ->postJson('/api/v1/projects', [
            'partnerId' => $clientOfB->id,
            'title' => 'Tentative',
            'serviceId' => $serviceOfA->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.serviceId.0', fn (string $m): bool => $m !== '');
});

it('garde le classement quand le service est ARCHIVÉ', function (): void {
    [$user, $company] = workspaceAccount();
    [$client, $service] = projectFixtures($company->id);

    $id = actingAs($user)->postJson('/api/v1/projects', [
        'partnerId' => $client->id,
        'title' => 'Chantier classé',
        'serviceId' => $service->id,
    ])->assertCreated()->json('id');

    app(TenantContext::class)->activateCompany($company->id);
    $service->delete();

    // Soft delete : la colonne survit, mais le nom n'est plus rendu — l'écran
    // affiche « — » plutôt qu'un libellé inventé.
    actingAs($user)
        ->getJson("/api/v1/projects/{$id}")
        ->assertOk()
        ->assertJsonPath('serviceId', $service->id)
        ->assertJsonPath('serviceName', null);
});

// ── Le référentiel lui-même ──────────────────────────────────────────────

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

it('conserve les projets d un service archivé', function (): void {
    [$user, $company] = workspaceAccount();
    [$client, $service] = projectFixtures($company->id);

    actingAs($user)->postJson('/api/v1/projects', [
        'partnerId' => $client->id,
        'title' => 'Chantier classé',
        'serviceId' => $service->id,
    ])->assertCreated();

    app(TenantContext::class)->activateCompany($company->id);
    $service->delete();

    // Soft delete : le projet demeure, et la colonne conserve la trace du
    // classement passé plutôt que de l'effacer.
    expect(Project::query()->where('service_id', $service->id)->count())->toBe(1);
});
