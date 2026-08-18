<?php

declare(strict_types=1);

use App\Modules\Cash\Models\CashMovement;
use App\Modules\Documents\Models\Document;
use App\Modules\Tenancy\Services\TenantContext;

use function Pest\Laravel\actingAs;

/**
 * Le journal de caisse agrège DEUX sources : les écritures saisies
 * (`cash_movements`) et les règlements reçus sur les factures (`payments`).
 *
 * Ce que ces cas verrouillent, et qui manquait : un règlement encaissé sur une
 * facture doit apparaître dans la caisse et compter dans l'encaissement total.
 * Sans cela, l'écran affichait 0,00 MAD le jour où 7 000 MAD étaient rentrés.
 */

/** Somme des mouvements SAISIS d'une période, telle que la caisse la voyait avant. */
function cashOnly(string $companyId): int
{
    app(TenantContext::class)->activateCompany($companyId);

    return (int) CashMovement::query()
        ->where('amount_cents', '>', 0)
        ->where('occurred_at', '>=', now()->subDays(29)->toDateString())
        ->sum('amount_cents');
}

it('fait apparaître un règlement de facture dans le journal de caisse', function (): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/payments", [
        'amountCents' => 700_000,
        'paidOn' => now()->toDateString(),
        'method' => 'transfer',
    ])->assertCreated();

    $entries = actingAs($user)
        ->getJson('/api/v1/cash/movements?period=last30&direction=inflow')
        ->assertOk()
        ->json('movements');

    $payments = array_values(array_filter(
        $entries,
        static fn (array $row): bool => $row['source'] === 'payment',
    ));

    expect($payments)->toHaveCount(1);

    $entry = $payments[0];

    expect($entry['amountCents'])->toBe(700_000);
    expect($entry['method'])->toBe('transfer');
    // Le nom du client vient de la FACTURE, figé à l'émission.
    expect($entry['clientName'])->toBe($invoice->client_name);
    expect($entry['invoiceNumber'])->toBe($invoice->number);
    // Un règlement n'entre dans aucune caisse physique.
    expect($entry['registerName'])->toBeNull();
});

it('ajoute le règlement à l encaissement total', function (): void {
    [$user, $company] = workspaceAccount();
    $before = cashOnly($company->id);
    $invoice = payableInvoice($company->id);

    actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/payments", [
        'amountCents' => 700_000,
        'paidOn' => now()->toDateString(),
        'method' => 'cash',
    ])->assertCreated();

    actingAs($user)
        ->getJson('/api/v1/cash/movements?period=last30&direction=inflow')
        ->assertOk()
        ->assertJsonPath('inflowCents', $before + 700_000)
        // Le solde suit : un règlement est un encaissement, il compte des deux
        // côtés — le total encaissé ET la trésorerie nette.
        ->assertJsonPath('balanceCents', fn (int $balance): bool => $balance >= 700_000);
});

it('retire de la caisse un règlement supprimé', function (): void {
    [$user, $company] = workspaceAccount();
    $before = cashOnly($company->id);
    $invoice = payableInvoice($company->id);

    $paymentId = actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/payments", [
        'amountCents' => 700_000,
        'paidOn' => now()->toDateString(),
        'method' => 'cash',
    ])->json('id');

    actingAs($user)->deleteJson("/api/v1/payments/{$paymentId}")->assertNoContent();

    // Le soft delete du règlement suffit : la caisse LIT `payments`, elle n'en
    // tient pas de copie qu'il faudrait penser à défaire. C'est précisément ce
    // qu'un mouvement miroir créé à l'écriture n'aurait pas garanti.
    actingAs($user)
        ->getJson('/api/v1/cash/movements?period=last30&direction=inflow')
        ->assertOk()
        ->assertJsonPath('inflowCents', $before);
});

it('n affiche aucun règlement dans le filtre des décaissements', function (): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/payments", [
        'amountCents' => 700_000,
        'paidOn' => now()->toDateString(),
        'method' => 'cash',
    ])->assertCreated();

    $entries = actingAs($user)
        ->getJson('/api/v1/cash/movements?period=last30&direction=outflow')
        ->assertOk()
        // Le filtre ne touche QUE la liste : le cumul encaissé reste juste.
        ->assertJsonPath('inflowCents', fn (int $inflow): bool => $inflow >= 700_000)
        ->json('movements');

    foreach ($entries as $row) {
        expect($row['source'])->toBe('cash');
        expect($row['amountCents'])->toBeLessThan(0);
    }
});

it('exclut un règlement hors de la période demandée', function (): void {
    [$user, $company] = workspaceAccount();
    $before = cashOnly($company->id);
    $invoice = payableInvoice($company->id);

    actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/payments", [
        'amountCents' => 700_000,
        // Encaissé il y a 40 jours : hors de « 30 derniers jours ».
        'paidOn' => now()->subDays(40)->toDateString(),
        'method' => 'cash',
    ])->assertCreated();

    $recent = actingAs($user)
        ->getJson('/api/v1/cash/movements?period=last7&direction=inflow')
        ->assertOk()
        ->json('movements');

    expect(array_column($recent, 'source'))->not->toContain('payment');

    actingAs($user)
        ->getJson('/api/v1/cash/movements?period=last30&direction=inflow')
        ->assertOk()
        ->assertJsonPath('inflowCents', $before);
});

it('ne laisse pas la caisse d une société voir les règlements d une autre', function (): void {
    [$userA, $companyA] = workspaceAccount();
    [$userB, $companyB] = workspaceAccount();

    $invoiceOfB = payableInvoice($companyB->id);

    actingAs($userB)->postJson("/api/v1/invoices/{$invoiceOfB->id}/payments", [
        'amountCents' => 700_000,
        'paidOn' => now()->toDateString(),
        'method' => 'cash',
    ])->assertCreated();

    $expected = cashOnly($companyA->id);

    // Le scope tenant s'applique à `payments` comme au reste : la fusion en
    // lecture n'ouvre aucune porte dérobée entre sociétés (§5.6).
    $summary = actingAs($userA)
        ->getJson('/api/v1/cash/movements?period=last30&direction=inflow')
        ->assertOk()
        ->json();

    expect($summary['inflowCents'])->toBe($expected);

    foreach ($summary['movements'] as $row) {
        expect($row['source'])->toBe('cash');
    }
});

it('classe les règlements et les mouvements dans le même ordre chronologique', function (): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/payments", [
        'amountCents' => 700_000,
        'paidOn' => now()->toDateString(),
        'method' => 'cash',
    ])->assertCreated();

    actingAs($user)->postJson('/api/v1/cash/movements', [
        'occurredAt' => now()->subDay()->toDateString(),
        'label' => 'Encaissement comptoir',
        'method' => 'cash',
        'registerName' => 'Caisse principale',
        'amountCents' => 25_000,
        'currency' => 'MAD',
    ])->assertCreated();

    $dates = array_column(
        actingAs($user)
            ->getJson('/api/v1/cash/movements?period=last30&direction=inflow')
            ->assertOk()
            ->json('movements'),
        'occurredAt',
    );

    $sorted = $dates;
    rsort($sorted);

    expect($dates)->toBe($sorted);
});

it('rejette une écriture de caisse portant l identifiant d un règlement', function (): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    $paymentId = actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/payments", [
        'amountCents' => 700_000,
        'paidOn' => now()->toDateString(),
        'method' => 'cash',
    ])->json('id');

    // Un règlement ne se corrige QUE depuis sa facture : y toucher par l'écran
    // Caisses laisserait `documents.paid_cents` et le statut derrière lui.
    // C'est ce 404 que le champ `source` évite à l'utilisateur de rencontrer.
    actingAs($user)->patchJson("/api/v1/cash/movements/{$paymentId}", [
        'occurredAt' => now()->toDateString(),
        'label' => 'Détournement',
        'method' => 'cash',
        'registerName' => 'Caisse principale',
        'amountCents' => 1,
        'currency' => 'MAD',
    ])->assertNotFound();

    actingAs($user)->deleteJson("/api/v1/cash/movements/{$paymentId}")->assertNotFound();

    app(TenantContext::class)->activateCompany($company->id);
    expect(Document::query()->find($invoice->id)?->paid_cents)->toBe(700_000);
});
