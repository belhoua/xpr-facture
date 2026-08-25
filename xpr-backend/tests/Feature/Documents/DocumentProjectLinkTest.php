<?php

declare(strict_types=1);

use App\Modules\Documents\Models\Document;
use App\Modules\Partners\Models\Partner;
use App\Modules\Projects\Models\Project;
use App\Modules\Tenancy\Services\TenantContext;

use function Pest\Laravel\actingAs;

/**
 * Rattachement document → projet, et les totaux qui en découlent.
 *
 * La règle centrale : un document ne porte que le projet de SON client. La
 * condition croise `documents.partner_id` et `projects.partner_id`, deux
 * tables — ni CHECK ni clé étrangère ne l'expriment, elle se tient dans
 * `DocumentWriteService`, et c'est ce fichier qui l'atteste.
 */

/** Client de la société active, avec un projet ouvert. */
function clientWithProject(string $companyId, string $title = 'Chantier Nord'): Project
{
    app(TenantContext::class)->activateCompany($companyId);

    $partner = Partner::factory()->client()->create(['ice' => null]);

    return Project::factory()->create([
        'company_id' => $companyId,
        'partner_id' => $partner->id,
        'title' => $title,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function projectDocumentPayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'invoice',
        'items' => [['label' => 'Prestation', 'quantity' => '1', 'unitPriceCents' => 400_000]],
    ], $overrides);
}

it('rattache une facture au projet de son client', function (): void {
    [$user, $company] = workspaceAccount();
    $project = clientWithProject($company->id);

    actingAs($user)
        ->postJson('/api/v1/documents', projectDocumentPayload([
            'partnerId' => $project->partner_id,
            'projectId' => $project->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('projectId', $project->id);
});

it('rattache une SITUATION au projet de son client', function (): void {
    [$user, $company] = workspaceAccount();
    $project = clientWithProject($company->id);

    actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'situation',
            'partnerId' => $project->partner_id,
            'projectId' => $project->id,
            'subject' => 'Situation n°1',
            'totalCents' => 500_000,
            'paidCents' => 200_000,
        ])
        ->assertCreated()
        ->assertJsonPath('projectId', $project->id);
});

it('refuse le projet d un AUTRE client', function (): void {
    [$user, $company] = workspaceAccount();
    $project = clientWithProject($company->id);

    app(TenantContext::class)->activateCompany($company->id);
    $otherClient = Partner::factory()->client()->create(['ice' => null]);

    // Le cas d'un formulaire resté ouvert pendant qu'on change de client. Sans
    // ce refus, la facture d'un client s'imputerait au chantier d'un autre, et
    // les totaux par projet deviendraient faux sans que rien ne l'annonce.
    actingAs($user)
        ->postJson('/api/v1/documents', projectDocumentPayload([
            'partnerId' => $otherClient->id,
            'projectId' => $project->id,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.projectId.0', fn (string $message): bool => $message !== '');
});

it('refuse un projet sur une pièce saisie au NOM LIBRE', function (): void {
    [$user, $company] = workspaceAccount();
    $project = clientWithProject($company->id);

    // Le nom libre ouvre une fiche NEUVE (2026-08-17), qui n'est pas le client
    // du projet : le rattachement est donc refusé, exactement comme pour un
    // autre client choisi explicitement.
    actingAs($user)
        ->postJson('/api/v1/documents', projectDocumentPayload([
            'clientName' => 'Client Sans Projet',
            'projectId' => $project->id,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.projectId.0', fn (string $message): bool => $message !== '');
});

it('refuse le projet d une autre SOCIÉTÉ', function (): void {
    [, $companyA] = workspaceAccount();
    [$userB, $companyB] = workspaceAccount();

    $projectOfA = clientWithProject($companyA->id);

    app(TenantContext::class)->activateCompany($companyB->id);
    $clientOfB = Partner::factory()->client()->create(['ice' => null]);

    // 422 : la règle `exists` du FormRequest est scopée à la société active,
    // le projet de A est donc introuvable pour B (§5.3).
    actingAs($userB)
        ->postJson('/api/v1/documents', projectDocumentPayload([
            'partnerId' => $clientOfB->id,
            'projectId' => $projectOfA->id,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.projectId.0', fn (string $message): bool => $message !== '');
});

it('détache un projet en transmettant null', function (): void {
    [$user, $company] = workspaceAccount();
    $project = clientWithProject($company->id);

    $id = actingAs($user)
        ->postJson('/api/v1/documents', projectDocumentPayload([
            'partnerId' => $project->partner_id,
            'projectId' => $project->id,
        ]))
        ->assertCreated()
        ->json('id');

    // Un rattachement erroné doit pouvoir se défaire sans qu'on ait à en
    // fournir un autre.
    actingAs($user)
        ->patchJson("/api/v1/documents/{$id}", ['projectId' => null])
        ->assertOk()
        ->assertJsonPath('projectId', null);
});

it('laisse le projet INTACT sur un PATCH qui ne le porte pas', function (): void {
    [$user, $company] = workspaceAccount();
    $project = clientWithProject($company->id);

    $id = actingAs($user)
        ->postJson('/api/v1/documents', projectDocumentPayload([
            'partnerId' => $project->partner_id,
            'projectId' => $project->id,
        ]))
        ->assertCreated()
        ->json('id');

    // Corriger une note ne doit pas détacher le chantier : la clé absente et la
    // clé à null sont deux intentions différentes.
    actingAs($user)
        ->patchJson("/api/v1/documents/{$id}", ['notes' => 'Note corrigée'])
        ->assertOk()
        ->assertJsonPath('projectId', $project->id);
});

it('conserve le PROJET quand le devis devient facture', function (): void {
    [$user, $company] = workspaceAccount();
    $project = clientWithProject($company->id);

    $quote = actingAs($user)
        ->postJson('/api/v1/documents', projectDocumentPayload([
            'type' => 'quote',
            'partnerId' => $project->partner_id,
            'projectId' => $project->id,
        ]))
        ->assertCreated()
        ->json();

    // Le rattachement suivait le tiers mais PAS le chantier jusqu'au
    // 2026-08-24 : la facture produite repartait détachée, donc absente du
    // filtre « chantier » de l'écran par client et de ses quatre indicateurs.
    // Le devis y figurait, la facture non — le chantier montrait le proposé
    // sans jamais montrer le facturé.
    actingAs($user)
        ->postJson("/api/v1/documents/{$quote['id']}/convert")
        ->assertCreated()
        ->assertJsonPath('partnerId', $project->partner_id)
        ->assertJsonPath('projectId', $project->id);
});

// ── Le filtre de lecture, et les totaux qui en dépendent ──────────────────

it('restreint les totaux au projet demandé', function (string $key): void {
    [$user, $company] = workspaceAccount();
    $project = clientWithProject($company->id);
    $other = Project::factory()->create([
        'company_id' => $company->id,
        'partner_id' => $project->partner_id,
        'title' => 'Chantier Sud',
    ]);

    $invoice = static fn (string $projectId, int $amount): array => projectDocumentPayload([
        'partnerId' => $project->partner_id,
        'projectId' => $projectId,
        'items' => [['label' => 'Prestation', 'quantity' => '1', 'unitPriceCents' => $amount]],
    ]);

    actingAs($user)->postJson('/api/v1/documents', $invoice($project->id, 400_000))->assertCreated();
    actingAs($user)->postJson('/api/v1/documents', $invoice($other->id, 250_000))->assertCreated();
    // Une pièce du même client SANS projet : elle pèse dans l'encours du
    // client, jamais dans celui d'un chantier.
    actingAs($user)->postJson('/api/v1/documents', projectDocumentPayload([
        'partnerId' => $project->partner_id,
        'items' => [['label' => 'Divers', 'quantity' => '1', 'unitPriceCents' => 100_000]],
    ]))->assertCreated();

    // `projectId` ET `project_id` : l'API parle camelCase, mais un filtre
    // ignoré en silence rendrait les totaux de TOUT le client là où l'appelant
    // demandait un seul chantier — plausible et faux.
    actingAs($user)
        ->getJson("/api/v1/documents/summary?type=situation,invoice&{$key}={$project->id}")
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('totalCents', 400_000);

    // Sans le filtre, l'encours du client couvre les trois pièces.
    actingAs($user)
        ->getJson("/api/v1/documents/summary?type=situation,invoice&partnerId={$project->partner_id}")
        ->assertOk()
        ->assertJsonPath('count', 3)
        ->assertJsonPath('totalCents', 750_000);
})->with(['camelCase' => 'projectId', 'snake_case' => 'project_id']);

it('fait décrire par la liste exactement les lignes des totaux', function (): void {
    [$user, $company] = workspaceAccount();
    $project = clientWithProject($company->id);

    actingAs($user)->postJson('/api/v1/documents', projectDocumentPayload([
        'partnerId' => $project->partner_id,
        'projectId' => $project->id,
    ]))->assertCreated();

    actingAs($user)->postJson('/api/v1/documents', projectDocumentPayload([
        'partnerId' => $project->partner_id,
    ]))->assertCreated();

    actingAs($user)
        ->getJson("/api/v1/documents?type=situation,invoice&projectId={$project->id}")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.projectId', $project->id)
        // Le titre vient de la relation chargée d'emblée : sans elle, l'écran
        // ferait une requête par ligne pour l'afficher.
        ->assertJsonPath('data.0.projectTitle', 'Chantier Nord');
});

it('garde le rattachement quand le projet est ARCHIVÉ', function (): void {
    [$user, $company] = workspaceAccount();
    $project = clientWithProject($company->id);

    $id = actingAs($user)
        ->postJson('/api/v1/documents', projectDocumentPayload([
            'partnerId' => $project->partner_id,
            'projectId' => $project->id,
        ]))
        ->assertCreated()
        ->json('id');

    actingAs($user)->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();

    // Le projet est en soft delete : la pièce fiscale lui survit et garde sa
    // colonne. `projectTitle` retombe à null — la relation est filtrée par le
    // global scope —, ce que l'écran affiche comme « — » plutôt que d'inventer
    // un titre.
    app(TenantContext::class)->activateCompany($company->id);
    /** @var Document $document */
    $document = Document::query()->whereKey($id)->firstOrFail();

    expect($document->project_id)->toBe($project->id);
});
