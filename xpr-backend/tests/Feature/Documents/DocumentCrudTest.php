<?php

declare(strict_types=1);

use App\Modules\Documents\Models\Document;

use function Pest\Laravel\actingAs;

/**
 * Cycle de vie d'un document. La règle centrale testée ici est l'IMMUABILITÉ
 * fiscale (§3) : seul un brouillon se modifie ou se supprime ; un document émis
 * ne peut qu'être annulé.
 *
 * `workspaceAccount()` est défini dans tests/Pest.php et sème 7 factures (dont
 * 1 brouillon) numérotées 0001..0006 sur l'exercice courant, plus 1 devis.
 */

/**
 * Payload minimal d'un document à une ligne.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function documentPayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'invoice',
        'clientName' => 'Nouvelle Cliente SARL',
        'items' => [
            ['label' => 'Prestation de conseil', 'quantity' => '2', 'unitPriceCents' => 125_000],
        ],
    ], $overrides);
}

it('crée un document en brouillon, sans numéro', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/documents', documentPayload())
        ->assertCreated()
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('number', null)
        // Sans taux de TVA, la ligne est à 0 % : HT = TTC.
        ->assertJsonPath('subtotalCents', 250_000)
        ->assertJsonPath('totalCents', 250_000);
});

it('ignore un statut ou un numéro envoyés par le client', function (): void {
    [$user] = workspaceAccount();

    // Créer directement en « payé », ou choisir son numéro, contournerait la
    // séquence fiscale. Les champs ne sont tout simplement pas acceptés.
    actingAs($user)
        ->postJson('/api/v1/documents', documentPayload([
            'status' => 'paid',
            'number' => 'FAC-2026-9999',
        ]))
        ->assertCreated()
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('number', null);
});

it('recalcule les totaux et ignore ceux transmis par le client', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/documents', documentPayload([
            'totalCents' => 1,
            'subtotalCents' => 1,
        ]))
        ->assertCreated()
        ->assertJsonPath('totalCents', 250_000);
});

it('attribue le numéro suivant à l émission, sans trou', function (): void {
    [$user] = workspaceAccount();

    // La démo va jusqu'à 0006 → la suivante prend 0007 (§3). Millésime tiré de
    // l'exercice courant : pas d'année en dur, qui ferait échouer ce test au
    // 1er janvier.
    $year = now()->format('Y');
    $id = actingAs($user)->postJson('/api/v1/documents', documentPayload())->json('id');

    actingAs($user)
        ->postJson("/api/v1/documents/{$id}/issue")
        ->assertOk()
        ->assertJsonPath('status', 'sent')
        ->assertJsonPath('number', "FAC-{$year}-0007");
});

it('refuse d émettre un document sans ligne', function (): void {
    [$user] = workspaceAccount();

    // Un document vide consommerait un numéro de la séquence pour n'attester
    // de rien : le trou serait définitif.
    $id = actingAs($user)
        ->postJson('/api/v1/documents', documentPayload(['items' => []]))
        ->json('id');

    actingAs($user)->postJson("/api/v1/documents/{$id}/issue")->assertStatus(409);
});

it('refuse de réémettre un document déjà émis', function (): void {
    [$user] = workspaceAccount();
    $id = actingAs($user)->postJson('/api/v1/documents', documentPayload())->json('id');

    actingAs($user)->postJson("/api/v1/documents/{$id}/issue")->assertOk();
    actingAs($user)->postJson("/api/v1/documents/{$id}/issue")->assertStatus(409);
});

it('modifie un brouillon', function (): void {
    [$user] = workspaceAccount();
    $id = actingAs($user)->postJson('/api/v1/documents', documentPayload())->json('id');

    actingAs($user)
        ->patchJson("/api/v1/documents/{$id}", [
            'clientName' => 'Brouillon Corrigé',
            'items' => [
                ['label' => 'Autre prestation', 'quantity' => '1', 'unitPriceCents' => 300_000],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('clientName', 'Brouillon Corrigé')
        ->assertJsonPath('totalCents', 300_000)
        ->assertJsonCount(1, 'items');
});

it('ne vide pas les lignes quand le PATCH ne les transmet pas', function (): void {
    [$user] = workspaceAccount();
    $id = actingAs($user)->postJson('/api/v1/documents', documentPayload())->json('id');

    actingAs($user)
        ->patchJson("/api/v1/documents/{$id}", ['notes' => 'Merci de régler sous 30 jours.'])
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('totalCents', 250_000);
});

it('refuse de modifier un document émis (immuabilité)', function (): void {
    [$user] = workspaceAccount();
    $sent = Document::query()->where('status', 'sent')->firstOrFail();

    actingAs($user)
        ->patchJson("/api/v1/documents/{$sent->id}", ['clientName' => 'Tentative Interdite'])
        ->assertStatus(409);

    expect($sent->refresh()->client_name)->not->toBe('Tentative Interdite');
});

it('supprime un brouillon', function (): void {
    [$user] = workspaceAccount();
    $id = actingAs($user)->postJson('/api/v1/documents', documentPayload())->json('id');

    actingAs($user)->deleteJson("/api/v1/documents/{$id}")->assertNoContent();

    expect(Document::query()->find($id))->toBeNull();
});

it('refuse de supprimer un document émis (immuabilité)', function (): void {
    [$user] = workspaceAccount();
    $paid = Document::query()->where('status', 'paid')->firstOrFail();

    actingAs($user)->deleteJson("/api/v1/documents/{$paid->id}")->assertStatus(409);

    expect(Document::query()->find($paid->id))->not->toBeNull();
});

it('annule un document émis', function (): void {
    [$user] = workspaceAccount();
    $sent = Document::query()->where('status', 'sent')->firstOrFail();

    actingAs($user)
        ->postJson("/api/v1/documents/{$sent->id}/cancel")
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');
});

it('refuse d annuler un brouillon — il se supprime', function (): void {
    [$user] = workspaceAccount();
    $draft = Document::query()->where('status', 'draft')->firstOrFail();

    actingAs($user)->postJson("/api/v1/documents/{$draft->id}/cancel")->assertStatus(409);
});

it('refuse une transition d état impossible pour le type', function (): void {
    [$user] = workspaceAccount();
    $sent = Document::query()->where('type', 'invoice')->where('status', 'sent')->firstOrFail();

    // `accepted` appartient au cycle du DEVIS, pas à celui de la facture.
    actingAs($user)
        ->postJson("/api/v1/documents/{$sent->id}/status", ['status' => 'accepted'])
        ->assertStatus(409);
});

it('refuse de faire passer un brouillon directement à payé', function (): void {
    [$user] = workspaceAccount();
    $draft = Document::query()->where('status', 'draft')->firstOrFail();

    // Ce serait une créance réglée qui n'a jamais été facturée.
    actingAs($user)
        ->postJson("/api/v1/documents/{$draft->id}/status", ['status' => 'paid'])
        ->assertStatus(409);
});

it('isole les écritures de documents entre deux sociétés', function (): void {
    [$userA] = workspaceAccount();
    [, $companyB] = workspaceAccount();

    $foreignDraft = Document::withoutGlobalScopes()
        ->where('company_id', $companyB->id)
        ->where('status', 'draft')
        ->firstOrFail();

    // La société A ne doit même pas voir le document de B : 404, pas 403 (§5).
    actingAs($userA)
        ->patchJson("/api/v1/documents/{$foreignDraft->id}", ['clientName' => 'Intrusion'])
        ->assertNotFound();
});
