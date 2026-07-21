<?php

declare(strict_types=1);

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

// `workspaceAccount()` est défini dans tests/Pest.php pour rester disponible
// dans toutes les suites Workspace, y compris lors d'un run ciblé.

it('expose les statistiques du dashboard pour la société active', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->getJson('/api/v1/dashboard/stats?period=last30')
        ->assertOk()
        ->assertJsonStructure([
            'currency',
            'revenueCents',
            'revenueTrend',
            'collectedCents',
            'outstandingCents',
            'overdueCents',
            'overdueCount',
            'revenueSeries',
            'statusBreakdown',
        ])
        ->assertJsonPath('currency', 'MAD');
});

it('liste les factures de la société active avec pagination', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->getJson('/api/v1/invoices')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'clientName', 'status', 'totalCents', 'currency']],
            'meta' => ['total', 'page', 'perPage'],
        ])
        ->assertJsonPath('meta.total', 7);
});

it('filtre les factures par statut', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->getJson('/api/v1/invoices?status=draft')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'draft');
});

it('expose le résumé de caisse', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->getJson('/api/v1/cash/movements?period=last30')
        ->assertOk()
        ->assertJsonStructure([
            'balanceCents',
            'inflowCents',
            'outflowCents',
            'currency',
            'movements' => [['id', 'label', 'amountCents', 'method']],
        ])
        ->assertJsonCount(5, 'movements');
});

it('liste les membres de la société active', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->getJson('/api/v1/users')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'name', 'email', 'role', 'state']]])
        ->assertJsonPath('data.0.role', 'owner')
        ->assertJsonPath('data.0.state', 'active');
});

it('permet d inviter un collaborateur', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/users/invitations', [
            'name' => 'Fatima Benali',
            'email' => 'fatima@exemple.ma',
            'role' => 'accountant',
        ])
        ->assertCreated()
        ->assertJsonPath('email', 'fatima@exemple.ma')
        ->assertJsonPath('role', 'accountant')
        ->assertJsonPath('state', 'invited');
});

it('isole les factures entre deux sociétés', function (): void {
    [$userA, $companyA] = workspaceAccount();
    [$userB] = workspaceAccount();

    actingAs($userA)
        ->getJson('/api/v1/invoices')
        ->assertOk()
        ->assertJsonPath('meta.total', 7);

    actingAs($userB)
        ->getJson('/api/v1/invoices')
        ->assertOk()
        ->assertJsonPath('meta.total', 7);

    expect($companyA->id)->not->toBe($userB->resolveActiveCompany()?->id);
});

it('refuse les routes applicatives sans authentification', function (): void {
    getJson('/api/v1/invoices')->assertUnauthorized();
});
