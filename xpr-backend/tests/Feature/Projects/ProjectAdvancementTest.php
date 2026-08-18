<?php

declare(strict_types=1);

use App\Modules\Authentication\Models\User;
use App\Modules\Partners\Models\Partner;
use App\Modules\Projects\Models\Deliverable;
use App\Modules\Projects\Models\Project;
use App\Modules\Tenancy\Enums\Role;
use App\Modules\Tenancy\Services\TenantContext;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

/**
 * Avancement de projet et livrables remis au client.
 *
 * Quatre familles de règles sont éprouvées ici :
 *
 *  1. **L'ordre de la liste** : du plus récent au plus ancien, sans exception —
 *     c'est le contrat de l'écran, et il ne doit pas dépendre d'un ORDER BY
 *     oublié dans un filtre.
 *  2. **Les filtres** client et statut, seuls et combinés.
 *  3. **Le rattachement vient du CHEMIN**, jamais du corps de la requête : ni
 *     le projet d'un livrable, ni le client d'une autre société (§5.3).
 *  4. **Un projet annulé ne progresse plus** — la seule règle métier que
 *     l'avancement porte.
 */

/**
 * Rattache un utilisateur à une société avec un rôle donné.
 *
 * Redéfini ici plutôt qu'emprunté à `RolePerCompanyTest` : un helper défini
 * dans un autre fichier de test n'existe que si Pest a chargé ce fichier, et le
 * test échouerait dès qu'on lance ce seul fichier — ce qu'on fait précisément
 * quand on travaille sur ce module.
 *
 * Le périmètre Spatie est posé puis RESTAURÉ : le registrar est un singleton,
 * le laisser sur cette société fausserait les vérifications de droits du test
 * suivant.
 */
function projectMemberOf(User $user, string $companyId, Role $role): void
{
    $user->companies()->attach($companyId, ['joined_at' => now()]);
    $user->forceFill(['default_company_id' => $companyId])->save();

    $registrar = app(PermissionRegistrar::class);
    $previous = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($companyId);

    try {
        $user->assignRole($role->value);
    } finally {
        $registrar->setPermissionsTeamId($previous);
    }
}

/** Un client de la société active, prêt à porter des projets. */
function projectClient(string $companyId): Partner
{
    app(TenantContext::class)->activateCompany($companyId);

    return Partner::factory()->client()->create(['ice' => null]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function projectPayload(string $partnerId, array $overrides = []): array
{
    return array_merge([
        'partnerId' => $partnerId,
        'title' => 'Résidence Al Manar — lot A',
    ], $overrides);
}

it('crée un projet en cours à 0 % sans qu’on ait à le dire', function (): void {
    [$user, $company] = workspaceAccount();
    $client = projectClient($company->id);

    // Les deux valeurs viennent des défauts SQL : le service ne les invente
    // pas, il recharge la ligne écrite.
    actingAs($user)
        ->postJson('/api/v1/projects', projectPayload($client->id))
        ->assertCreated()
        ->assertJsonPath('title', 'Résidence Al Manar — lot A')
        ->assertJsonPath('status', 'in_progress')
        ->assertJsonPath('progressPercentage', 0)
        ->assertJsonPath('partnerId', $client->id)
        ->assertJsonPath('deliverableCount', 0);
});

it('refuse un projet sans client', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/projects', ['title' => 'Projet orphelin'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.partnerId.0', fn (string $message): bool => $message !== '');
});

it('refuse le client d’une AUTRE société', function (): void {
    [$userA, $companyA] = workspaceAccount();
    [, $companyB] = workspaceAccount();

    $clientOfB = projectClient($companyB->id);

    // 409 et non 422 : le client existe, mais pas ici. Une règle `exists` de
    // validation aurait interrogé `partners` SANS le global scope et l'aurait
    // accepté — c'est précisément le trou que ce test ferme (§5.3).
    actingAs($userA)
        ->postJson('/api/v1/projects', projectPayload($clientOfB->id))
        ->assertConflict();
});

it('liste les projets du plus RÉCENT au plus ancien', function (): void {
    [$user, $company] = workspaceAccount();
    $client = projectClient($company->id);

    foreach (['Premier', 'Deuxième', 'Troisième'] as $title) {
        actingAs($user)
            ->postJson('/api/v1/projects', projectPayload($client->id, ['title' => $title]))
            ->assertCreated();
    }

    // Le seeder de démonstration en a posé d'autres : on ne lit donc pas la
    // liste entière, mais on vérifie que les trois derniers créés arrivent en
    // tête, dans l'ordre inverse de leur création.
    $titles = actingAs($user)
        ->getJson("/api/v1/projects?partnerId={$client->id}")
        ->assertOk()
        ->json('data.*.title');

    expect($titles)->toBe(['Troisième', 'Deuxième', 'Premier']);
});

it('filtre les projets par client', function (): void {
    [$user, $company] = workspaceAccount();
    $client = projectClient($company->id);
    $other = projectClient($company->id);

    actingAs($user)
        ->postJson('/api/v1/projects', projectPayload($client->id, ['title' => 'Chantier suivi']))
        ->assertCreated();

    actingAs($user)
        ->postJson('/api/v1/projects', projectPayload($other->id, ['title' => 'Chantier voisin']))
        ->assertCreated();

    actingAs($user)
        ->getJson("/api/v1/projects?partnerId={$client->id}")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.title', 'Chantier suivi');
});

it('filtre les projets par statut', function (): void {
    [$user, $company] = workspaceAccount();
    $client = projectClient($company->id);

    actingAs($user)
        ->postJson('/api/v1/projects', projectPayload($client->id, [
            'title' => 'Mission close',
            'status' => 'completed',
            'progressPercentage' => 100,
        ]))
        ->assertCreated();

    actingAs($user)
        ->postJson('/api/v1/projects', projectPayload($client->id, ['title' => 'Mission ouverte']))
        ->assertCreated();

    actingAs($user)
        ->getJson("/api/v1/projects?partnerId={$client->id}&status=completed")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.title', 'Mission close');
});

it('met à jour l’avancement sans renvoyer le reste de la fiche', function (): void {
    [$user, $company] = workspaceAccount();
    $client = projectClient($company->id);

    $id = actingAs($user)
        ->postJson('/api/v1/projects', projectPayload($client->id))
        ->assertCreated()
        ->json('id');

    // PATCH partiel : le titre n'est pas transmis et ne doit pas être effacé.
    actingAs($user)
        ->patchJson("/api/v1/projects/{$id}", [
            'status' => 'monitoring',
            'progressPercentage' => 100,
        ])
        ->assertOk()
        ->assertJsonPath('status', 'monitoring')
        ->assertJsonPath('progressPercentage', 100)
        ->assertJsonPath('title', 'Résidence Al Manar — lot A');
});

it('refuse un avancement hors des bornes 0–100', function (): void {
    [$user, $company] = workspaceAccount();
    $client = projectClient($company->id);

    $id = actingAs($user)
        ->postJson('/api/v1/projects', projectPayload($client->id))
        ->assertCreated()
        ->json('id');

    // 422 et non une violation SQL brute remontée en 500 : la FormRequest double
    // la contrainte CHECK `projects_progress_range_check`.
    actingAs($user)
        ->patchJson("/api/v1/projects/{$id}", ['progressPercentage' => 140])
        ->assertUnprocessable();

    actingAs($user)
        ->patchJson("/api/v1/projects/{$id}", ['progressPercentage' => -5])
        ->assertUnprocessable();
});

it('refuse de faire avancer un projet ANNULÉ', function (): void {
    [$user, $company] = workspaceAccount();
    $client = projectClient($company->id);

    $id = actingAs($user)
        ->postJson('/api/v1/projects', projectPayload($client->id, ['status' => 'canceled']))
        ->assertCreated()
        ->json('id');

    // Un projet annulé ne se fera pas : lui pousser un pourcentage décrirait un
    // travail qui n'aura pas lieu.
    actingAs($user)
        ->patchJson("/api/v1/projects/{$id}", ['progressPercentage' => 50])
        ->assertConflict();

    // Le rouvrir DANS LA MÊME requête reste permis : le statut est appliqué
    // avant l'avancement, la garde voit alors un projet vivant.
    actingAs($user)
        ->patchJson("/api/v1/projects/{$id}", [
            'status' => 'in_progress',
            'progressPercentage' => 50,
        ])
        ->assertOk()
        ->assertJsonPath('progressPercentage', 50);
});

it('ajoute un livrable daté et le compte sur la fiche', function (): void {
    [$user, $company] = workspaceAccount();
    $client = projectClient($company->id);

    $id = actingAs($user)
        ->postJson('/api/v1/projects', projectPayload($client->id))
        ->assertCreated()
        ->json('id');

    actingAs($user)
        ->postJson("/api/v1/projects/{$id}/deliverables", [
            'title' => 'Notice technique',
            'deliveryDate' => '2026-03-12',
        ])
        ->assertCreated()
        ->assertJsonPath('title', 'Notice technique')
        ->assertJsonPath('deliveryDate', '2026-03-12')
        ->assertJsonPath('projectId', $id);

    actingAs($user)
        ->getJson("/api/v1/projects/{$id}")
        ->assertOk()
        ->assertJsonPath('deliverableCount', 1)
        ->assertJsonPath('deliverables.0.title', 'Notice technique');
});

it('range les livrables du plus RÉCEMMENT remis au plus ancien', function (): void {
    [$user, $company] = workspaceAccount();
    $client = projectClient($company->id);

    $id = actingAs($user)
        ->postJson('/api/v1/projects', projectPayload($client->id))
        ->assertCreated()
        ->json('id');

    // Saisis dans le DÉSORDRE : c'est la date de remise qui fait l'ordre, pas
    // celle de la saisie.
    foreach ([
        ['title' => 'Rapport d’avancement', 'deliveryDate' => '2026-05-20'],
        ['title' => 'Notice technique', 'deliveryDate' => '2026-01-08'],
        ['title' => 'Procès-verbal', 'deliveryDate' => '2026-07-02'],
    ] as $payload) {
        actingAs($user)
            ->postJson("/api/v1/projects/{$id}/deliverables", $payload)
            ->assertCreated();
    }

    $titles = actingAs($user)
        ->getJson("/api/v1/projects/{$id}")
        ->assertOk()
        ->json('deliverables.*.title');

    expect($titles)->toBe(['Procès-verbal', 'Rapport d’avancement', 'Notice technique']);
});

it('supprime un livrable sans toucher au projet', function (): void {
    [$user, $company] = workspaceAccount();
    $client = projectClient($company->id);

    $id = actingAs($user)
        ->postJson('/api/v1/projects', projectPayload($client->id))
        ->assertCreated()
        ->json('id');

    $deliverableId = actingAs($user)
        ->postJson("/api/v1/projects/{$id}/deliverables", [
            'title' => 'Notice technique',
            'deliveryDate' => '2026-03-12',
        ])
        ->assertCreated()
        ->json('id');

    actingAs($user)
        ->deleteJson("/api/v1/deliverables/{$deliverableId}")
        ->assertNoContent();

    actingAs($user)
        ->getJson("/api/v1/projects/{$id}")
        ->assertOk()
        ->assertJsonPath('deliverableCount', 0);

    // SOFT DELETE : la remise a eu lieu, sa trace demeure en base.
    app(TenantContext::class)->activateCompany($company->id);
    expect(Deliverable::withTrashed()->find($deliverableId))->not->toBeNull();
});

it('supprime un projet et le retire des listes', function (): void {
    [$user, $company] = workspaceAccount();
    $client = projectClient($company->id);

    $id = actingAs($user)
        ->postJson('/api/v1/projects', projectPayload($client->id))
        ->assertCreated()
        ->json('id');

    actingAs($user)
        ->deleteJson("/api/v1/projects/{$id}")
        ->assertNoContent();

    actingAs($user)
        ->getJson("/api/v1/projects/{$id}")
        ->assertNotFound();

    actingAs($user)
        ->getJson("/api/v1/projects?partnerId={$client->id}")
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

it('ne laisse pas une société voir les projets d’une autre', function (): void {
    [$userA, $companyA] = workspaceAccount();
    [$userB] = workspaceAccount();

    $clientOfA = projectClient($companyA->id);

    $id = actingAs($userA)
        ->postJson('/api/v1/projects', projectPayload($clientOfA->id))
        ->assertCreated()
        ->json('id');

    // Test d'isolation exigé par §5.6. B ne voit ni la fiche, ni la liste, et
    // ne peut pas y accrocher de livrable — 404 et non 403 : l'existence même
    // du projet ne doit pas fuiter.
    actingAs($userB)
        ->getJson("/api/v1/projects/{$id}")
        ->assertNotFound();

    actingAs($userB)
        ->getJson("/api/v1/projects?partnerId={$clientOfA->id}")
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    actingAs($userB)
        ->postJson("/api/v1/projects/{$id}/deliverables", [
            'title' => 'Livrable indiscret',
            'deliveryDate' => '2026-03-12',
        ])
        ->assertNotFound();

    actingAs($userB)
        ->deleteJson("/api/v1/projects/{$id}")
        ->assertNotFound();
});

it('cantonne un lecteur à la consultation des projets', function (): void {
    $user = User::factory()->create();
    [, $company] = workspaceAccount();

    projectMemberOf($user, $company->id, Role::Viewer);

    app(TenantContext::class)->activateCompany($company->id);
    $client = Partner::factory()->client()->create(['ice' => null]);
    $project = Project::factory()->inProgress()->create([
        'company_id' => $company->id,
        'partner_id' => $client->id,
    ]);

    actingAs($user)
        ->getJson('/api/v1/projects')
        ->assertOk();

    actingAs($user)
        ->postJson('/api/v1/projects', projectPayload($client->id))
        ->assertForbidden();

    actingAs($user)
        ->patchJson("/api/v1/projects/{$project->id}", ['progressPercentage' => 90])
        ->assertForbidden();

    actingAs($user)
        ->postJson("/api/v1/projects/{$project->id}/deliverables", [
            'title' => 'Notice technique',
            'deliveryDate' => '2026-03-12',
        ])
        ->assertForbidden();

    actingAs($user)
        ->deleteJson("/api/v1/projects/{$project->id}")
        ->assertForbidden();
});
