<?php

declare(strict_types=1);

use App\Modules\Partners\Models\Partner;
use App\Modules\Tenancy\Services\TenantContext;

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

it('liste les documents de la société active avec pagination', function (): void {
    [$user] = workspaceAccount();

    // Sans filtre de type : les 7 factures ET le devis. La table est unique,
    // la liste par défaut l'est aussi — c'est l'écran qui restreint.
    actingAs($user)
        ->getJson('/api/v1/documents')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'type', 'clientName', 'status', 'totalCents', 'currency']],
            'meta' => ['total', 'page', 'perPage'],
        ])
        ->assertJsonPath('meta.total', 8);
});

it('filtre les documents par type', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->getJson('/api/v1/documents?type=invoice')
        ->assertOk()
        ->assertJsonPath('meta.total', 7);

    actingAs($user)
        ->getJson('/api/v1/documents?type=quote')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

it('filtre les documents par statut', function (): void {
    [$user] = workspaceAccount();

    // `paid` et non `draft` : la démonstration ne contient plus de brouillon
    // depuis le 2026-08-14 — la facture naît numérotée et envoyée. Ce que ce
    // test vérifie est le FILTRE, pas l'existence d'un état particulier.
    actingAs($user)
        ->getJson('/api/v1/documents?type=invoice&status=paid')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.status', 'paid');
});

it('expose le résumé de caisse', function (): void {
    [$user] = workspaceAccount();

    // Le jeu de démonstration pose 7 mouvements sur les 30 derniers jours :
    // 5 encaissements et 2 décaissements (loyer, fournitures).
    actingAs($user)
        ->getJson('/api/v1/cash/movements?period=last30')
        ->assertOk()
        ->assertJsonStructure([
            'balanceCents',
            'inflowCents',
            'outflowCents',
            'currency',
            'movements' => [['id', 'label', 'amountCents', 'method', 'clientName']],
        ])
        ->assertJsonCount(7, 'movements');
});

it('ne liste que les encaissements sans amputer les cumuls', function (): void {
    [$user] = workspaceAccount();

    $full = actingAs($user)
        ->getJson('/api/v1/cash/movements?period=last30')
        ->assertOk()
        ->json();

    $inflowOnly = actingAs($user)
        ->getJson('/api/v1/cash/movements?period=last30&direction=inflow')
        ->assertOk()
        ->json();

    // La LISTE est filtrée…
    expect($inflowOnly['movements'])->toHaveCount(5);
    expect(array_column($inflowOnly['movements'], 'amountCents'))
        ->each->toBeGreaterThan(0);

    // …mais PAS les cumuls. C'est ce qui permet à l'écran Caisses de n'afficher
    // que les entrées tout en montrant un total encaissé juste : laisser le
    // filtre d'affichage amputer les agrégats donnerait un « solde » égal aux
    // seuls encaissements — un chiffre faux, et faux sans le dire.
    expect($inflowOnly['balanceCents'])->toBe($full['balanceCents']);
    expect($inflowOnly['inflowCents'])->toBe($full['inflowCents']);
    expect($inflowOnly['outflowCents'])->toBe($full['outflowCents']);
    expect($inflowOnly['outflowCents'])->toBeGreaterThan(0);
});

it('rend le nom du client sur un encaissement rattaché', function (): void {
    [$user] = workspaceAccount();

    $movements = actingAs($user)
        ->getJson('/api/v1/cash/movements?period=last30&direction=inflow')
        ->assertOk()
        ->json('movements');

    $names = array_column($movements, 'clientName');

    expect($names)->toContain('Riad Azur');

    // Un décaissement n'a aucun tiers de ce répertoire — un loyer, un achat de
    // fournitures. `clientName` y vaut `null`, et l'écran affiche « — » plutôt
    // qu'un nom deviné.
    $all = actingAs($user)
        ->getJson('/api/v1/cash/movements?period=last30')
        ->assertOk()
        ->json('movements');

    $outflows = array_values(array_filter(
        $all,
        static fn (array $row): bool => $row['amountCents'] < 0,
    ));

    expect($outflows)->not->toBeEmpty();
    expect(array_column($outflows, 'clientName'))->each->toBeNull();
});

it('refuse le tiers d’une AUTRE société sur un mouvement de caisse', function (): void {
    [$userA] = workspaceAccount();
    [, $companyB] = workspaceAccount();

    app(TenantContext::class)->activateCompany($companyB->id);
    $partnerOfB = Partner::factory()->client()->create(['ice' => null]);

    // 409 et non 422 : le tiers existe, mais pas ici. Une règle `exists` de
    // validation aurait interrogé `partners` SANS le global scope et l'aurait
    // accepté — c'est précisément le trou que ce test ferme (§5.3).
    actingAs($userA)
        ->postJson('/api/v1/cash/movements', [
            'partnerId' => $partnerOfB->id,
            'occurredAt' => now()->toDateString(),
            'label' => 'Encaissement indiscret',
            'method' => 'cash',
            'registerName' => 'Caisse principale',
            'amountCents' => 100_000,
            'currency' => 'MAD',
        ])
        ->assertConflict();
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

it('isole les documents entre deux sociétés', function (): void {
    [$userA, $companyA] = workspaceAccount();
    [$userB] = workspaceAccount();

    actingAs($userA)
        ->getJson('/api/v1/documents')
        ->assertOk()
        ->assertJsonPath('meta.total', 8);

    actingAs($userB)
        ->getJson('/api/v1/documents')
        ->assertOk()
        ->assertJsonPath('meta.total', 8);

    expect($companyA->id)->not->toBe($userB->resolveActiveCompany()?->id);
});

it('refuse les routes applicatives sans authentification', function (): void {
    getJson('/api/v1/documents')->assertUnauthorized();
});
