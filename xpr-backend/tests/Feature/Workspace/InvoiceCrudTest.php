<?php

declare(strict_types=1);

use App\Modules\Invoices\Models\Invoice;

use function Pest\Laravel\actingAs;

/**
 * CRUD des factures. La règle centrale testée ici est l'immuabilité fiscale
 * (§3) : seul un brouillon se modifie ou se supprime ; une facture validée ne
 * peut qu'être annulée. `workspaceAccount()` est défini dans WorkspaceApiTest
 * et sème 7 factures (dont 1 brouillon, numéros FAC-2026-0001..0006).
 */
it('crée une facture en brouillon sans numéro', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/invoices', [
            'clientName' => 'Nouvelle Cliente SARL',
            'issuedAt' => null,
            'dueAt' => null,
            'status' => 'draft',
            'totalCents' => 250000,
            'currency' => 'MAD',
        ])
        ->assertCreated()
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('number', null)
        ->assertJsonPath('totalCents', 250000);
});

it('attribue un numéro continu à la création d une facture validée', function (): void {
    [$user] = workspaceAccount();

    // La démo va jusqu'à FAC-2026-0006 → la suivante prend 0007, sans trou (§3).
    actingAs($user)
        ->postJson('/api/v1/invoices', [
            'clientName' => 'Client Validé',
            'issuedAt' => '2026-07-20',
            'dueAt' => '2026-08-20',
            'status' => 'sent',
            'totalCents' => 480000,
            'currency' => 'MAD',
        ])
        ->assertCreated()
        ->assertJsonPath('number', 'FAC-2026-0007');
});

it('modifie une facture en brouillon', function (): void {
    [$user] = workspaceAccount();
    $draft = Invoice::query()->where('status', 'draft')->firstOrFail();

    actingAs($user)
        ->patchJson("/api/v1/invoices/{$draft->id}", [
            'clientName' => 'Brouillon Corrigé',
            'issuedAt' => null,
            'dueAt' => null,
            'status' => 'draft',
            'totalCents' => 300000,
            'currency' => 'MAD',
        ])
        ->assertOk()
        ->assertJsonPath('clientName', 'Brouillon Corrigé')
        ->assertJsonPath('totalCents', 300000);
});

it('numérote un brouillon lorsqu il est validé par une modification', function (): void {
    [$user] = workspaceAccount();
    $draft = Invoice::query()->where('status', 'draft')->firstOrFail();

    actingAs($user)
        ->patchJson("/api/v1/invoices/{$draft->id}", [
            'clientName' => 'Devenu Envoyé',
            'issuedAt' => '2026-07-20',
            'dueAt' => '2026-08-20',
            'status' => 'sent',
            'totalCents' => 300000,
            'currency' => 'MAD',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'sent')
        ->assertJsonPath('number', 'FAC-2026-0007');
});

it('refuse de modifier une facture validée (immuabilité)', function (): void {
    [$user] = workspaceAccount();
    $sent = Invoice::query()->where('status', 'sent')->firstOrFail();

    actingAs($user)
        ->patchJson("/api/v1/invoices/{$sent->id}", [
            'clientName' => 'Tentative Interdite',
            'issuedAt' => '2026-07-20',
            'dueAt' => '2026-08-20',
            'status' => 'sent',
            'totalCents' => 1,
            'currency' => 'MAD',
        ])
        ->assertStatus(409);

    $sent->refresh();
    expect($sent->client_name)->not->toBe('Tentative Interdite');
});

it('supprime une facture en brouillon', function (): void {
    [$user] = workspaceAccount();
    $draft = Invoice::query()->where('status', 'draft')->firstOrFail();

    actingAs($user)
        ->deleteJson("/api/v1/invoices/{$draft->id}")
        ->assertNoContent();

    expect(Invoice::query()->find($draft->id))->toBeNull();
});

it('refuse de supprimer une facture validée (immuabilité)', function (): void {
    [$user] = workspaceAccount();
    $paid = Invoice::query()->where('status', 'paid')->firstOrFail();

    actingAs($user)
        ->deleteJson("/api/v1/invoices/{$paid->id}")
        ->assertStatus(409);

    expect(Invoice::query()->find($paid->id))->not->toBeNull();
});

it('annule une facture validée', function (): void {
    [$user] = workspaceAccount();
    $sent = Invoice::query()->where('status', 'sent')->firstOrFail();

    actingAs($user)
        ->postJson("/api/v1/invoices/{$sent->id}/cancel")
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');
});

it('refuse d annuler un brouillon', function (): void {
    [$user] = workspaceAccount();
    $draft = Invoice::query()->where('status', 'draft')->firstOrFail();

    actingAs($user)
        ->postJson("/api/v1/invoices/{$draft->id}/cancel")
        ->assertStatus(409);
});

it('isole les écritures de factures entre deux sociétés', function (): void {
    [$userA] = workspaceAccount();
    [, $companyB] = workspaceAccount();

    $foreignDraft = Invoice::withoutGlobalScopes()
        ->where('company_id', $companyB->id)
        ->where('status', 'draft')
        ->firstOrFail();

    // La société A ne doit même pas voir la facture de B : binding → 404 (§5).
    actingAs($userA)
        ->patchJson("/api/v1/invoices/{$foreignDraft->id}", [
            'clientName' => 'Intrusion',
            'issuedAt' => null,
            'dueAt' => null,
            'status' => 'draft',
            'totalCents' => 1,
            'currency' => 'MAD',
        ])
        ->assertNotFound();
});
