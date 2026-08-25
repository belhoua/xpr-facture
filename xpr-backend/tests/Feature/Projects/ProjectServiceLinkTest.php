<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Partners\Models\Partner;
use App\Modules\Projects\Models\Project;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;

use function Pest\Laravel\actingAs;

/**
 * Classement d'un projet sous une PRESTATION DU CATALOGUE.
 *
 * `projects.service_id` pointe `products` depuis le 2026-08-26 : les
 * prestations sont les articles de type « service », ceux que l'écran
 * `/services` gère. Une table `services` distincte les portait auparavant, que
 * seul le déroulant du projet alimentait — une prestation créée dans
 * `/services` n'y apparaissait donc jamais (cf. la migration
 * `point_project_service_to_catalog`).
 *
 * Trois règles sont tenues ici : le classement est FACULTATIF, il ne vise que
 * des SERVICES — pas les biens que la même table porte —, et il reste cloisonné
 * par société comme toute donnée métier (§5).
 */

/**
 * Client + prestation de la société active, prêts à porter un projet.
 *
 * @return array{0: Partner, 1: Product}
 */
function projectFixtures(string $companyId): array
{
    app(TenantContext::class)->activateCompany($companyId);

    return [
        Partner::factory()->client()->create(['ice' => null]),
        Product::factory()->service()->create(['name' => 'Contrôle technique de construction']),
    ];
}

it('crée un projet SANS service', function (): void {
    [$user, $company] = workspaceAccount();
    [$client] = projectFixtures($company->id);

    // Le cas nominal d'une société qui ne classe pas ses missions : le champ
    // est absent du payload, et rien ne doit l'exiger.
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

it('classe un projet sous une prestation DU CATALOGUE', function (): void {
    [$user, $company] = workspaceAccount();
    [$client, $service] = projectFixtures($company->id);

    // LE test de la régression corrigée : l'identifiant vient de `products`,
    // et c'est celui que `GET /products?type=service` — la liste du déroulant —
    // rend. Avant le 2026-08-26, la clé étrangère visait une autre table et cet
    // appel repartait en 422 « service inexistant ».
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

it('propose au classement ce que le déroulant liste, et l y retrouve', function (): void {
    [$user, $company] = workspaceAccount();
    [$client] = projectFixtures($company->id);

    // Bout en bout du parcours signalé : une prestation créée depuis l'écran
    // `/services` — donc un POST sur le catalogue — doit être classable
    // immédiatement. Les deux écrans parlaient à deux tables différentes.
    $serviceId = actingAs($user)
        ->postJson('/api/v1/products', [
            'type' => 'service',
            'name' => 'Béton armé',
            'unitPriceCents' => 0,
        ])
        ->assertCreated()
        ->json('id');

    $listed = array_column(
        actingAs($user)->getJson('/api/v1/products?type=service')->assertOk()->json('data'),
        'id',
    );
    expect($listed)->toContain($serviceId);

    actingAs($user)
        ->postJson('/api/v1/projects', [
            'partnerId' => $client->id,
            'title' => 'Villa Anfa',
            'serviceId' => $serviceId,
        ])
        ->assertCreated()
        ->assertJsonPath('serviceName', 'Béton armé');
});

it('refuse de classer un projet sous un BIEN', function (): void {
    [$user, $company] = workspaceAccount();
    [$client] = projectFixtures($company->id);

    app(TenantContext::class)->activateCompany($company->id);
    $good = Product::factory()->good()->create(['name' => 'Ramette papier A4']);

    // La table porte les deux types. Sans le filtre `type = 'service'`, un
    // chantier se classerait sous un article de quincaillerie.
    actingAs($user)
        ->postJson('/api/v1/projects', [
            'partnerId' => $client->id,
            'title' => 'Chantier mal classé',
            'serviceId' => $good->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.serviceId.0', fn (string $m): bool => $m !== '');
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

    // La règle `exists` est scopée sur `company_id` : `Rule::exists` interroge
    // la table SANS le global scope, et la prestation de A serait acceptée sans
    // ce filtre écrit à la main (§5.3).
    actingAs($userB)
        ->postJson('/api/v1/projects', [
            'partnerId' => $clientOfB->id,
            'title' => 'Tentative',
            'serviceId' => $serviceOfA->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.serviceId.0', fn (string $m): bool => $m !== '');
});

it('garde le classement quand la prestation est ARCHIVÉE', function (): void {
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
    // affiche « — » plutôt qu'un libellé inventé. C'est ce qui permet
    // d'archiver une prestation du catalogue sans réécrire l'historique des
    // projets qu'elle classait.
    actingAs($user)
        ->getJson("/api/v1/projects/{$id}")
        ->assertOk()
        ->assertJsonPath('serviceId', $service->id)
        ->assertJsonPath('serviceName', null);

    expect(Project::query()->where('service_id', $service->id)->count())->toBe(1);
});

it('refuse de classer sous une prestation ARCHIVÉE', function (): void {
    [$user, $company] = workspaceAccount();
    [$client, $service] = projectFixtures($company->id);

    app(TenantContext::class)->activateCompany($company->id);
    $service->delete();

    // Conserver un classement passé, oui ; en poser un nouveau sous une
    // prestation retirée du catalogue, non — le déroulant ne la propose plus.
    actingAs($user)
        ->postJson('/api/v1/projects', [
            'partnerId' => $client->id,
            'title' => 'Chantier tardif',
            'serviceId' => $service->id,
        ])
        ->assertStatus(422);
});
