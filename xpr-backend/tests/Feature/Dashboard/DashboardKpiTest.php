<?php

declare(strict_types=1);

use App\Modules\Cash\Models\CashMovement;
use App\Modules\Partners\Enums\PartnerType;
use App\Modules\Partners\Models\Partner;
use App\Modules\Tenancy\Services\TenantContext;

use function Pest\Laravel\actingAs;

/**
 * KPI ajoutés au tableau de bord : portefeuille de tiers, trésorerie et
 * classement clients. Ils alimentent les cartes de l'écran d'accueil.
 */
it('compte les tiers actifs par rôle, les fiches `both` dans les deux', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    // Le jeu de démo peuple déjà le répertoire : on mesure l'ÉCART produit par
    // ces trois fiches, un total absolu casserait au prochain ajout.
    $before = actingAs($user)->getJson('/api/v1/dashboard/stats')->assertOk()->json();

    app(TenantContext::class)->activateCompany($company->id);
    Partner::factory()->client()->create(['ice' => null]);
    Partner::factory()->supplier()->create(['ice' => null]);
    Partner::factory()->both()->create(['ice' => null]);

    $after = actingAs($user)->getJson('/api/v1/dashboard/stats')->assertOk()->json();

    // +1 client et +1 « both » ⇒ +2 clients ; +1 fournisseur et +1 « both » ⇒ +2
    expect($after['activeClients'] - $before['activeClients'])->toBe(2)
        ->and($after['activeSuppliers'] - $before['activeSuppliers'])->toBe(2);
});

it('ignore les tiers archivés et inactifs', function (): void {
    [$user, $company] = workspaceAccount();

    $before = actingAs($user)->getJson('/api/v1/dashboard/stats')->assertOk()->json('activeClients');

    app(TenantContext::class)->activateCompany($company->id);
    Partner::factory()->client()->inactive()->create(['ice' => null]);
    Partner::factory()->client()->create(['ice' => null])->delete();

    $after = actingAs($user)->getJson('/api/v1/dashboard/stats')->assertOk()->json('activeClients');

    expect($after)->toBe($before);
});

it('expose la trésorerie de la période', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    CashMovement::query()->delete();
    CashMovement::create([
        'occurred_at' => now()->subDays(3),
        'label' => 'Encaissement',
        'method' => 'cash',
        'register_name' => 'Caisse principale',
        'amount_cents' => 500000,
        'currency' => 'MAD',
    ]);
    CashMovement::create([
        'occurred_at' => now()->subDays(1),
        'label' => 'Achat',
        'method' => 'cash',
        'register_name' => 'Caisse principale',
        'amount_cents' => -150000,
        'currency' => 'MAD',
    ]);

    actingAs($user)
        ->getJson('/api/v1/dashboard/stats')
        ->assertOk()
        ->assertJsonPath('cashBalanceCents', 350000)
        ->assertJsonPath('cashInflowCents', 500000)
        // Sorties en valeur absolue : le signe est porté par le libellé.
        ->assertJsonPath('cashOutflowCents', 150000);
});

it('classe les cinq premiers clients par chiffre d affaires', function (): void {
    [$user] = workspaceAccount();

    $top = actingAs($user)->getJson('/api/v1/dashboard/stats')->assertOk()->json('topClients');

    expect($top)->not->toBeEmpty()
        ->and(count($top))->toBeLessThanOrEqual(5);

    // Tri décroissant strict sur le montant
    $totals = array_column($top, 'totalCents');
    $sorted = $totals;
    rsort($sorted);

    expect($totals)->toBe($sorted)
        ->and($top[0])->toHaveKeys(['name', 'totalCents', 'invoiceCount']);
});

it('isole les KPI entre deux sociétés', function (): void {
    [, $companyA] = workspaceAccount();
    [$userB, $companyB] = workspaceAccount();

    app(TenantContext::class)->activateCompany($companyA->id);
    Partner::factory()->client()->count(5)->create(['ice' => null]);

    $statsB = actingAs($userB)->getJson('/api/v1/dashboard/stats')->assertOk()->json();

    app(TenantContext::class)->activateCompany($companyB->id);
    $clientsOfB = Partner::query()->ofType(PartnerType::Client)
        ->where('is_active', true)
        ->count();

    // Les 5 fiches de A ne gonflent pas le compteur de B.
    expect($statsB['activeClients'])->toBe($clientsOfB);
});
