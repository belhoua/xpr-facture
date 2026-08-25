<?php

declare(strict_types=1);

use App\Modules\Cash\Models\CashMovement;
use App\Modules\Documents\Models\Document;
use App\Modules\Partners\Enums\PartnerType;
use App\Modules\Partners\Models\Partner;
use App\Modules\Payments\Models\Payment;
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

/**
 * ── ENCAISSÉ et RESTANT DÛ (2026-08-26) ───────────────────────────────────
 *
 * Les deux sommaient le `total_cents` des factures selon leur STATUT : une
 * facture de 240 MAD réglée à 140 affichait 240 encaissés ET 240 restant dus.
 * Ils se lisent désormais sur les règlements enregistrés.
 *
 * Le décor est REMIS À PLAT — factures et règlements du jeu de démonstration
 * effacés — parce que ces deux cartes se mesurent en absolu : un écart ne dirait
 * pas si la formule est juste, seulement qu'elle a bougé.
 */
it('lit l’encaissé sur les RÈGLEMENTS et non sur le total des factures', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    Payment::query()->forceDelete();
    Document::query()->forceDelete();

    $invoice = Document::factory()->create([
        'company_id' => $company->id,
        'type' => 'invoice',
        'status' => 'partial',
        'number' => 'FAC-TEST-0001',
        'issued_at' => now()->subDays(2),
        'total_cents' => 24_000,
        'paid_cents' => 14_000,
    ]);

    // Deux règlements pour 140 MAD sur une facture de 240.
    foreach ([10_000, 4_000] as $amount) {
        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'amount_cents' => $amount,
            'currency' => 'MAD',
            'paid_on' => now()->subDay()->toDateString(),
            'method' => 'cash',
        ]);
    }

    $stats = actingAs($user)
        ->getJson('/api/v1/dashboard/stats')
        ->assertOk()
        ->assertJsonPath('revenueCents', 24_000)
        // 140,00 MAD : la somme des règlements, pas les 240,00 de la facture.
        ->assertJsonPath('collectedCents', 14_000)
        // 100,00 MAD : ce qui reste réellement dû.
        ->assertJsonPath('outstandingCents', 10_000)
        ->json();

    // La courbe suit la même règle que la carte, sinon l'écran se contredirait
    // lui-même. La SOMME de la série et non son premier point : `last30`
    // chevauche deux mois dès qu'on est en début de mois, et l'index du point
    // qui porte la facture dépendrait alors du jour où le test tourne.
    expect(array_sum(array_column($stats['revenueSeries'], 'collectedCents')))->toBe(14_000);
});

it('ne compte pas un règlement SUPPRIMÉ dans l’encaissé', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    Payment::query()->forceDelete();
    Document::query()->forceDelete();

    $invoice = Document::factory()->create([
        'company_id' => $company->id,
        'type' => 'invoice',
        'status' => 'sent',
        'number' => 'FAC-TEST-0002',
        'issued_at' => now()->subDays(2),
        'total_cents' => 24_000,
        'paid_cents' => 0,
    ]);

    $payment = Payment::query()->create([
        'invoice_id' => $invoice->id,
        'amount_cents' => 14_000,
        'currency' => 'MAD',
        'paid_on' => now()->subDay()->toDateString(),
        'method' => 'cash',
    ]);

    // Un chèque revenu impayé : le règlement est retiré, l'encaissé retombe et
    // le dû remonte. Le soft delete s'en charge — la requête agrégée ne voit
    // que les règlements vivants.
    $payment->delete();

    actingAs($user)
        ->getJson('/api/v1/dashboard/stats')
        ->assertOk()
        ->assertJsonPath('collectedCents', 0)
        ->assertJsonPath('outstandingCents', 24_000);
});

it('exclut les BROUILLONS du restant dû', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    Payment::query()->forceDelete();
    Document::query()->forceDelete();

    Document::factory()->create([
        'company_id' => $company->id,
        'type' => 'invoice',
        'status' => 'draft',
        'number' => null,
        'issued_at' => null,
        'total_cents' => 50_000,
        'paid_cents' => 0,
    ]);

    // Un brouillon entre dans le chiffre d'affaires mais n'est dû par
    // personne : les deux cartes ne s'additionnent donc plus à la main, et
    // c'est la lecture juste — une pièce non émise n'a été réclamée à
    // personne.
    actingAs($user)
        ->getJson('/api/v1/dashboard/stats')
        ->assertOk()
        ->assertJsonPath('revenueCents', 50_000)
        ->assertJsonPath('outstandingCents', 0);
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
