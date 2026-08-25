<?php

declare(strict_types=1);

use App\Modules\Documents\Models\Document;
use App\Modules\Partners\Models\Partner;
use App\Modules\Projects\Models\Project;
use App\Modules\Tenancy\Services\TenantContext;

use function Pest\Laravel\actingAs;

/**
 * Ouverture AUTOMATIQUE d'un chantier depuis un devis (2026-08-25).
 *
 * Un devis accepté devient un chantier à suivre, et le rattachement fait à la
 * main était systématiquement oublié : l'écran « Avancement de projet » restait
 * vide alors que l'affaire tournait.
 *
 * Ce que ce fichier encadre, au-delà du cas nominal : le PÉRIMÈTRE. La règle ne
 * vaut que pour le devis, elle exige un objet et un client, et elle ne remplace
 * jamais un projet déjà choisi — trois bornes qu'un futur remaniement pourrait
 * franchir sans s'en apercevoir.
 */

/** Un client de la société active. */
function autoProjectClient(string $companyId): Partner
{
    app(TenantContext::class)->activateCompany($companyId);

    return Partner::factory()->client()->create(['ice' => null]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function autoProjectPayload(string $partnerId, array $overrides = []): array
{
    return array_merge([
        'type' => 'quote',
        'partnerId' => $partnerId,
        'subject' => 'Système d’exploitation',
        'items' => [['label' => 'Prestation', 'quantity' => '1', 'unitPriceCents' => 100_000]],
    ], $overrides);
}

it('ouvre un chantier au nom de l’objet du devis', function (): void {
    [$user, $company] = workspaceAccount();
    $client = autoProjectClient($company->id);

    $projectId = actingAs($user)
        ->postJson('/api/v1/documents', autoProjectPayload($client->id))
        ->assertCreated()
        ->assertJsonPath('projectId', fn (?string $value): bool => $value !== null)
        ->json('projectId');

    app(TenantContext::class)->activateCompany($company->id);

    /** @var Project $project `findOrFail` est typé `Project|Collection` : la
     * surcharge qui rend une collection ne s'applique qu'à un tableau de clés. */
    $project = Project::query()->findOrFail($projectId);

    expect($project->title)->toBe('Système d’exploitation');
    expect($project->partner_id)->toBe($client->id);
    expect($project->status->value)->toBe('in_progress');
    expect($project->progress_percentage)->toBe(0);
});

it('ouvre le chantier même quand le formulaire transmet projectId à null', function (): void {
    [$user, $company] = workspaceAccount();
    $client = autoProjectClient($company->id);

    // C'est le cas RÉEL : le formulaire émet toujours la clé, à `null` quand le
    // déroulant est vide. Une règle qui ne se déclencherait que sur clé absente
    // ne se déclencherait jamais depuis l'interface — c'est précisément le
    // défaut constaté.
    actingAs($user)
        ->postJson('/api/v1/documents', autoProjectPayload($client->id, ['projectId' => null]))
        ->assertCreated()
        ->assertJsonPath('projectId', fn (?string $value): bool => $value !== null);
});

it('RÉUTILISE le chantier existant du même client', function (): void {
    [$user, $company] = workspaceAccount();
    $client = autoProjectClient($company->id);

    $first = actingAs($user)
        ->postJson('/api/v1/documents', autoProjectPayload($client->id))
        ->assertCreated()
        ->json('projectId');

    // Casse et espaces différents : c'est le même chantier pour l'œil, ce doit
    // être le même en base. Sans cette normalisation, l'écran d'avancement
    // afficherait deux fois la même affaire.
    $second = actingAs($user)
        ->postJson('/api/v1/documents', autoProjectPayload($client->id, [
            'subject' => '  système d’EXPLOITATION  ',
        ]))
        ->assertCreated()
        ->json('projectId');

    expect($second)->toBe($first);

    app(TenantContext::class)->activateCompany($company->id);
    expect(Project::query()->where('partner_id', $client->id)->count())->toBe(1);
});

it('ouvre un chantier DISTINCT pour un autre client de même objet', function (): void {
    [$user, $company] = workspaceAccount();
    $one = autoProjectClient($company->id);
    $two = autoProjectClient($company->id);

    $first = actingAs($user)
        ->postJson('/api/v1/documents', autoProjectPayload($one->id))
        ->assertCreated()
        ->json('projectId');

    // `projects.partner_id` est NOT NULL et un projet n'appartient qu'à un
    // client : réutiliser celui du premier rattacherait le devis du second au
    // chantier d'un tiers, ce que `projectColumn()` refuse par ailleurs en 422.
    $second = actingAs($user)
        ->postJson('/api/v1/documents', autoProjectPayload($two->id))
        ->assertCreated()
        ->json('projectId');

    expect($second)->not->toBe($first);
});

it('respecte le chantier explicitement choisi', function (): void {
    [$user, $company] = workspaceAccount();
    $client = autoProjectClient($company->id);

    app(TenantContext::class)->activateCompany($company->id);
    $chosen = Project::factory()->inProgress()->create([
        'company_id' => $company->id,
        'partner_id' => $client->id,
        'title' => 'Chantier choisi à la main',
    ]);

    actingAs($user)
        ->postJson('/api/v1/documents', autoProjectPayload($client->id, [
            'projectId' => $chosen->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('projectId', $chosen->id);

    // Aucun second chantier n'a été ouvert au nom de l'objet.
    expect(Project::query()->where('partner_id', $client->id)->count())->toBe(1);
});

it('n’ouvre AUCUN chantier sans objet', function (): void {
    [$user, $company] = workspaceAccount();
    $client = autoProjectClient($company->id);

    // Sans objet, il n'y a pas de nom à donner au chantier. C'est aussi le seul
    // moyen de créer un devis qui n'en porte pas.
    actingAs($user)
        ->postJson('/api/v1/documents', autoProjectPayload($client->id, ['subject' => null]))
        ->assertCreated()
        ->assertJsonPath('projectId', null);
});

it('n’ouvre AUCUN chantier sur une saisie au nom libre', function (): void {
    [$user] = workspaceAccount();

    // Le nom libre ouvre une fiche tiers (2026-08-17), et le projet suit donc
    // le client créé à l'instant. Le test vaut par ce qu'il fixe : le
    // rattachement passe par le tiers résolu, jamais par un `partner_id` nul
    // que `projects` refuserait.
    $created = actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'quote',
            'clientName' => 'Client Sans Fiche',
            'subject' => 'Étude de sol',
            'items' => [['label' => 'Prestation', 'quantity' => '1', 'unitPriceCents' => 100_000]],
        ])
        ->assertCreated()
        ->json();

    expect($created['partnerId'])->not->toBeNull();
    expect($created['projectId'])->not->toBeNull();
});

it('ne vaut QUE pour le devis', function (string $type): void {
    [$user, $company] = workspaceAccount();
    $client = autoProjectClient($company->id);

    // Une facture née d'un devis hérite déjà du projet de celui-ci ; une
    // facture directe ou une proforma n'ouvrent aucun chantier.
    actingAs($user)
        ->postJson('/api/v1/documents', autoProjectPayload($client->id, ['type' => $type]))
        ->assertCreated()
        ->assertJsonPath('projectId', null);

    app(TenantContext::class)->activateCompany($company->id);
    expect(Project::query()->where('partner_id', $client->id)->count())->toBe(0);
})->with(['invoice', 'proforma']);

it('ouvre le chantier à la MODIFICATION d’un devis qui n’en a pas', function (): void {
    [$user, $company] = workspaceAccount();

    $quote = Document::query()->where('type', 'quote')->whereNotNull('partner_id')->firstOrFail();
    $quote->forceFill(['project_id' => null])->save();

    // Le devis existant reste rattachable : sans cela, seuls les devis créés
    // après la levée en profiteraient, et l'historique resterait muet.
    actingAs($user)
        ->patchJson("/api/v1/documents/{$quote->id}", ['subject' => 'Reprise de façade'])
        ->assertOk()
        ->assertJsonPath('projectId', fn (?string $value): bool => $value !== null);

    app(TenantContext::class)->activateCompany($company->id);
    expect(Project::query()->where('title', 'Reprise de façade')->exists())->toBeTrue();
});
