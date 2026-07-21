<?php

declare(strict_types=1);

use App\Modules\Invoices\Models\Invoice;
use App\Modules\Partners\Models\Partner;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\QueryException;

use function Pest\Laravel\actingAs;

/**
 * Rattachement facture → tiers.
 *
 * La règle centrale : `partner_id` sert à AGRÉGER, `client_name` à RESTITUER
 * le document. Le second est figé à l'émission et ne suit jamais un renommage
 * du premier (§3, immuabilité).
 */
it('fige la raison sociale du tiers sur la facture', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $partner = Partner::factory()->client()->create([
        'legal_name' => 'Comptoir Atlas S.A.R.L.',
        // L'enseigne ne doit PAS être retenue : le document engage l'entité
        // légale, pas le nom commercial.
        'trade_name' => 'Comptoir',
        'ice' => null,
    ]);

    actingAs($user)
        ->postJson('/api/v1/invoices', [
            'partnerId' => $partner->id,
            'issuedAt' => now()->toDateString(),
            'dueAt' => now()->addMonth()->toDateString(),
            'status' => 'sent',
            'totalCents' => 450000,
            'currency' => 'MAD',
        ])
        ->assertCreated()
        ->assertJsonPath('partnerId', $partner->id)
        ->assertJsonPath('clientName', 'Comptoir Atlas S.A.R.L.');
});

it('ne réécrit pas les factures émises quand le tiers est renommé', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $partner = Partner::factory()->client()->create([
        'legal_name' => 'Ancienne Raison S.A.R.L.',
        'ice' => null,
    ]);

    $invoiceId = actingAs($user)
        ->postJson('/api/v1/invoices', [
            'partnerId' => $partner->id,
            'issuedAt' => now()->toDateString(),
            'dueAt' => now()->addMonth()->toDateString(),
            'status' => 'sent',
            'totalCents' => 120000,
            'currency' => 'MAD',
        ])
        ->assertCreated()
        ->json('id');

    // `json()` rend du mixed : on fixe le type avant de requêter, sinon
    // findOrFail() peut aussi bien rendre une collection qu'un modèle.
    $invoiceId = (string) $invoiceId;

    // Le tiers change de raison sociale…
    actingAs($user)
        ->patchJson("/api/v1/partners/{$partner->id}", [
            'type' => 'client',
            'legalName' => 'Nouvelle Raison S.A.',
        ])
        ->assertOk();

    // … la facture DÉJÀ ÉMISE garde le nom qu'elle portait. C'est ce qui a été
    // envoyé au client : le modifier réécrirait un document fiscal.
    app(TenantContext::class)->activateCompany($company->id);
    $invoice = Invoice::query()->findOrFail($invoiceId);

    expect($invoice->client_name)->toBe('Ancienne Raison S.A.R.L.')
        ->and($invoice->partner_id)->toBe($partner->id)
        // Le lien reste exploitable pour les agrégats
        ->and($invoice->partner?->legal_name)->toBe('Nouvelle Raison S.A.');
});

it('accepte un client de passage sans tiers', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/invoices', [
            'clientName' => 'Client Occasionnel',
            'issuedAt' => now()->toDateString(),
            'dueAt' => now()->addMonth()->toDateString(),
            'status' => 'draft',
            'totalCents' => 90000,
            'currency' => 'MAD',
        ])
        ->assertCreated()
        ->assertJsonPath('partnerId', null)
        ->assertJsonPath('clientName', 'Client Occasionnel');
});

it('exige un nom quand aucun tiers n est fourni', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/invoices', [
            'issuedAt' => now()->toDateString(),
            'dueAt' => now()->addMonth()->toDateString(),
            'status' => 'draft',
            'totalCents' => 90000,
            'currency' => 'MAD',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.clientName.0', fn (string $m): bool => $m !== '');
});

it('refuse le tiers d une autre société', function (): void {
    [, $companyA] = workspaceAccount();
    [$userB] = workspaceAccount();

    app(TenantContext::class)->activateCompany($companyA->id);
    $partnerOfA = Partner::factory()->client()->create(['ice' => null]);

    // B tente de rattacher sa facture au client de A : la règle `exists` est
    // scopée à la société active, donc 422 — pas de fuite inter-sociétés.
    actingAs($userB)
        ->postJson('/api/v1/invoices', [
            'partnerId' => $partnerOfA->id,
            'issuedAt' => now()->toDateString(),
            'dueAt' => now()->addMonth()->toDateString(),
            'status' => 'draft',
            'totalCents' => 90000,
            'currency' => 'MAD',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.partnerId.0', fn (string $m): bool => $m !== '');
});

it('empêche la suppression d un tiers référencé par une facture', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $partner = Partner::factory()->client()->create(['ice' => null]);

    actingAs($user)->postJson('/api/v1/invoices', [
        'partnerId' => $partner->id,
        'issuedAt' => now()->toDateString(),
        'dueAt' => now()->addMonth()->toDateString(),
        'status' => 'sent',
        'totalCents' => 75000,
        'currency' => 'MAD',
    ])->assertCreated();

    // L'archivage reste permis : il n'efface pas la ligne.
    actingAs($user)->deleteJson("/api/v1/partners/{$partner->id}")->assertNoContent();

    app(TenantContext::class)->activateCompany($company->id);

    // Mais une suppression DURE est refusée par la contrainte RESTRICT : les
    // documents qui le référencent doivent rester lisibles.
    expect(fn () => Partner::withTrashed()->findOrFail($partner->id)->forceDelete())
        ->toThrow(QueryException::class);
});

it('regroupe le classement clients sur le tiers, pas sur le nom', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    Invoice::query()->delete();
    $partner = Partner::factory()->client()->create([
        'legal_name' => 'Groupement Test S.A.',
        'ice' => null,
    ]);

    foreach ([100000, 250000] as $amount) {
        actingAs($user)->postJson('/api/v1/invoices', [
            'partnerId' => $partner->id,
            'issuedAt' => now()->toDateString(),
            'dueAt' => now()->addMonth()->toDateString(),
            'status' => 'sent',
            'totalCents' => $amount,
            'currency' => 'MAD',
        ])->assertCreated();
    }

    // Le tiers est renommé entre deux factures : sans regroupement par
    // `partner_id`, les deux documents apparaîtraient comme deux clients.
    actingAs($user)->patchJson("/api/v1/partners/{$partner->id}", [
        'type' => 'client',
        'legalName' => 'Groupement Renommé S.A.',
    ])->assertOk();

    actingAs($user)->postJson('/api/v1/invoices', [
        'partnerId' => $partner->id,
        'issuedAt' => now()->toDateString(),
        'dueAt' => now()->addMonth()->toDateString(),
        'status' => 'sent',
        'totalCents' => 50000,
        'currency' => 'MAD',
    ])->assertCreated();

    $top = actingAs($user)->getJson('/api/v1/dashboard/stats')->assertOk()->json('topClients');

    expect($top)->toHaveCount(1)
        ->and($top[0]['partnerId'])->toBe($partner->id)
        ->and($top[0]['invoiceCount'])->toBe(3)
        ->and($top[0]['totalCents'])->toBe(400000);
});
