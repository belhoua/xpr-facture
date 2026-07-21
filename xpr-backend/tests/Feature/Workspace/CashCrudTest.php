<?php

declare(strict_types=1);

use App\Modules\Cash\Models\CashMovement;

use function Pest\Laravel\actingAs;

/**
 * CRUD des mouvements de caisse. Pas d'immuabilité ici : un mouvement se crée,
 * se corrige et se supprime librement. `workspaceAccount()` (WorkspaceApiTest)
 * sème 5 mouvements pour la société active.
 */
it('crée un encaissement', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/cash/movements', [
            'occurredAt' => '2026-07-20',
            'label' => 'Encaissement espèces comptoir',
            'method' => 'cash',
            'registerName' => 'Caisse principale',
            'amountCents' => 150000,
            'currency' => 'MAD',
        ])
        ->assertCreated()
        ->assertJsonPath('amountCents', 150000)
        ->assertJsonPath('method', 'cash');
});

it('crée un décaissement (montant négatif)', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/cash/movements', [
            'occurredAt' => '2026-07-20',
            'label' => 'Achat fournitures',
            'method' => 'card',
            'registerName' => 'Caisse principale',
            'amountCents' => -45000,
            'currency' => 'MAD',
        ])
        ->assertCreated()
        ->assertJsonPath('amountCents', -45000);
});

it('rejette un mouvement de montant nul', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/cash/movements', [
            'occurredAt' => '2026-07-20',
            'label' => 'Ligne fantôme',
            'method' => 'cash',
            'registerName' => 'Caisse principale',
            'amountCents' => 0,
            'currency' => 'MAD',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.amountCents.0', fn ($m): bool => is_string($m));
});

it('modifie un mouvement de caisse', function (): void {
    [$user] = workspaceAccount();
    $movement = CashMovement::query()->firstOrFail();

    actingAs($user)
        ->patchJson("/api/v1/cash/movements/{$movement->id}", [
            'occurredAt' => '2026-07-19',
            'label' => 'Libellé corrigé',
            'method' => 'cheque',
            'registerName' => 'Caisse secondaire',
            'amountCents' => 90000,
            'currency' => 'MAD',
        ])
        ->assertOk()
        ->assertJsonPath('label', 'Libellé corrigé')
        ->assertJsonPath('method', 'cheque')
        ->assertJsonPath('amountCents', 90000);
});

it('supprime un mouvement de caisse', function (): void {
    [$user] = workspaceAccount();
    $movement = CashMovement::query()->firstOrFail();

    actingAs($user)
        ->deleteJson("/api/v1/cash/movements/{$movement->id}")
        ->assertNoContent();

    expect(CashMovement::query()->find($movement->id))->toBeNull();
});

it('isole les écritures de caisse entre deux sociétés', function (): void {
    [$userA] = workspaceAccount();
    [, $companyB] = workspaceAccount();

    $foreign = CashMovement::withoutGlobalScopes()
        ->where('company_id', $companyB->id)
        ->firstOrFail();

    actingAs($userA)
        ->deleteJson("/api/v1/cash/movements/{$foreign->id}")
        ->assertNotFound();

    expect(CashMovement::withoutGlobalScopes()->find($foreign->id))->not->toBeNull();
});
