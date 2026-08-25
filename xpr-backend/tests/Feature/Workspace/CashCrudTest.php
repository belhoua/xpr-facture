<?php

declare(strict_types=1);

use App\Modules\Cash\Models\CashMovement;
use App\Modules\Tenancy\Services\TenantContext;

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

// ── Nature de la charge (2026-08-26) ──────────────────────────────────────
//
// Le journal disait combien était sorti et par quel moyen, jamais POUR QUOI.
// Le libellé porte le détail de l'écriture, la charge en porte la nature.

it('classe un décaissement par sa nature de charge', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/cash/movements', [
            'occurredAt' => '2026-07-20',
            'label' => 'Ramettes A4 et toner',
            'charge' => 'Fournitures de bureau',
            'method' => 'card',
            'registerName' => 'Caisse principale',
            'amountCents' => -45_000,
            'currency' => 'MAD',
        ])
        ->assertCreated()
        ->assertJsonPath('charge', 'Fournitures de bureau');
});

it('IGNORE la charge sur un encaissement', function (): void {
    [$user] = workspaceAccount();

    // Le cas du formulaire dont on bascule le sens après avoir saisi une
    // nature : la laisser passer classerait une entrée d'argent en « Loyer ».
    // Vidée plutôt que refusée, comme les champs d'effet sur un règlement en
    // espèces.
    actingAs($user)
        ->postJson('/api/v1/cash/movements', [
            'occurredAt' => '2026-07-20',
            'label' => 'Encaissement comptoir',
            'charge' => 'Loyer',
            'method' => 'cash',
            'registerName' => 'Caisse principale',
            'amountCents' => 150_000,
            'currency' => 'MAD',
        ])
        ->assertCreated()
        ->assertJsonPath('charge', null);
});

it('liste les natures de charge déjà employées, sans doublon ni période', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    CashMovement::query()->delete();

    $spend = static fn (string $charge, string $on): array => [
        'occurredAt' => $on,
        'label' => 'Dépense',
        'charge' => $charge,
        'method' => 'cash',
        'registerName' => 'Caisse principale',
        'amountCents' => -1_000,
        'currency' => 'MAD',
    ];

    // Deux fois la même nature, et une dépense VIEILLE d'un an : la liste ne
    // doit ni la dédoubler ni oublier l'ancienne. Un déroulant borné à la
    // période pousserait à ressaisir « Loyer », donc à créer un doublon
    // d'orthographe — exactement ce que la liste sert à éviter.
    actingAs($user)->postJson('/api/v1/cash/movements', $spend('Loyer', now()->subYear()->toDateString()))->assertCreated();
    actingAs($user)->postJson('/api/v1/cash/movements', $spend('Fournitures', now()->toDateString()))->assertCreated();
    actingAs($user)->postJson('/api/v1/cash/movements', $spend('Fournitures', now()->toDateString()))->assertCreated();

    actingAs($user)
        ->getJson('/api/v1/cash/charges')
        ->assertOk()
        // Alphabétique, et chaque nature une seule fois.
        ->assertExactJson(['data' => ['Fournitures', 'Loyer']]);
});

it('ne laisse pas une société voir les charges d’une autre', function (): void {
    [$userA, $companyA] = workspaceAccount();
    [$userB, $companyB] = workspaceAccount();

    foreach ([[$userA, $companyA, 'Loyer Casablanca'], [$userB, $companyB, 'Loyer Rabat']] as [$user, $company, $charge]) {
        app(TenantContext::class)->activateCompany($company->id);
        CashMovement::query()->delete();

        actingAs($user)->postJson('/api/v1/cash/movements', [
            'occurredAt' => now()->toDateString(),
            'label' => 'Loyer du mois',
            'charge' => $charge,
            'method' => 'transfer',
            'registerName' => 'Banque',
            'amountCents' => -500_000,
            'currency' => 'MAD',
        ])->assertCreated();
    }

    // Isolation tenant (§5.6) sur un agrégat : une fuite s'y verrait comme une
    // nature de charge appartenant à une autre société.
    actingAs($userA)
        ->getJson('/api/v1/cash/charges')
        ->assertOk()
        ->assertExactJson(['data' => ['Loyer Casablanca']]);
});
