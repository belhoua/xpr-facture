<?php

declare(strict_types=1);

use App\Modules\Authentication\Models\User;
use App\Modules\Cash\Models\CashMovement;
use App\Modules\Cash\Services\PaymentCashMirror;
use App\Modules\Payments\Models\Payment;
use App\Modules\Tenancy\Services\TenantContext;

use function Pest\Laravel\actingAs;

/**
 * MIROIR des règlements dans le journal de caisse (2026-08-25).
 *
 * Le journal fusionnait les deux tables en lecture ; il porte désormais une
 * COPIE de chaque règlement. Ce fichier encadre ce que la duplication rend
 * possible et qui n'existait pas avant :
 *
 *  1. le DOUBLE COMPTAGE — la faute la plus probable, et la plus silencieuse :
 *     il suffirait que la lecture rouvre une requête sur `payments` pour que
 *     100 MAD encaissés en affichent 200 ;
 *  2. la DIVERGENCE — une copie qu'on corrigerait sans corriger l'original ;
 *  3. l'ORPHELIN — une copie qui survivrait à son règlement.
 *
 * Les montants sont mesurés en ÉCART et non en absolu : chaque société de test
 * reçoit le jeu de démonstration, qui porte déjà des mouvements de caisse et
 * des règlements. Écrire les totaux attendus en dur les ferait tous casser au
 * premier ajout à ce jeu, sans qu'aucune règle n'ait bougé.
 */

/**
 * Les trois cumuls de la période, tels que l'écran les recevrait.
 *
 * @return array{inflow: int, balance: int, lines: int}
 */
function cashTotals(User $user): array
{
    $payload = actingAs($user)
        ->getJson('/api/v1/cash/movements?period=last30')
        ->assertOk()
        ->json();

    return [
        'inflow' => $payload['inflowCents'],
        'balance' => $payload['balanceCents'],
        'lines' => count($payload['movements']),
    ];
}

/** Le mouvement copié d'un règlement, ou `null`. */
function mirrorOf(Payment $payment): ?CashMovement
{
    return CashMovement::query()->where('payment_id', $payment->id)->first();
}

it('écrit un mouvement de caisse à l’enregistrement d’un règlement', function (): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amountCents' => 10_000,
            'paidOn' => now()->toDateString(),
            'method' => 'cash',
        ])
        ->assertCreated();

    app(TenantContext::class)->activateCompany($company->id);
    $payment = Payment::query()->where('invoice_id', $invoice->id)->firstOrFail();
    $movement = mirrorOf($payment);

    expect($movement)->not->toBeNull();
    expect($movement?->amount_cents)->toBe(10_000);
    expect($movement?->occurred_at->toDateString())->toBe($payment->paid_on->toDateString());
    expect($movement?->method)->toBe('cash');
    expect($movement?->partner_id)->toBe($invoice->partner_id);
    // Composé avec la clé de traduction, pas avec une chaîne figée : le
    // libellé est désormais ÉCRIT dans la langue du compte qui enregistre, et
    // la suite de tests ne tourne pas forcément en français.
    expect($movement?->label)->toBe(
        __('Payment of invoice :number', ['number' => $invoice->number]),
    );
    // Aucune caisse physique : un règlement n'entre pas au comptoir.
    expect($movement?->register_name)->toBeNull();
});

it('ne compte pas le règlement DEUX FOIS', function (): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    $before = cashTotals($user);

    actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amountCents' => 10_000,
            'paidOn' => now()->toDateString(),
            'method' => 'cash',
        ])
        ->assertCreated();

    $after = cashTotals($user);

    // La garde centrale du passage au miroir : les règlements sont DANS
    // `cash_movements`, les relire depuis `payments` doublerait chaque
    // encaissement — sur les trois cartes comme dans le journal.
    expect($after['inflow'] - $before['inflow'])->toBe(10_000);
    expect($after['balance'] - $before['balance'])->toBe(10_000);
    expect($after['lines'] - $before['lines'])->toBe(1);
});

it('retire le mouvement quand le règlement est supprimé', function (): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    $before = cashTotals($user);

    $paymentId = actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amountCents' => 10_000,
            'paidOn' => now()->toDateString(),
            'method' => 'cash',
        ])
        ->assertCreated()
        ->json('id');

    actingAs($user)->deleteJson("/api/v1/payments/{$paymentId}")->assertNoContent();

    app(TenantContext::class)->activateCompany($company->id);

    // Suppression DURE de la copie, là où le règlement part en soft delete :
    // un reflet conservé compterait double à la première lecture faite sans
    // son scope.
    expect(CashMovement::query()->where('payment_id', $paymentId)->exists())->toBeFalse();

    expect(cashTotals($user))->toEqual($before);
});

it('accepte un règlement par LCN, que la caisse refusait', function (): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    // `cash_movements_method_check` ignorait `lcn` et `deposit` : sans
    // l'élargissement de la contrainte, la copie échouerait — et avec elle,
    // la transaction du règlement.
    actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amountCents' => 10_000,
            'paidOn' => now()->toDateString(),
            'method' => 'lcn',
            'checkNumber' => '4477',
            'checkStatus' => 'pending',
        ])
        ->assertCreated();

    app(TenantContext::class)->activateCompany($company->id);
    expect(CashMovement::query()->where('method', 'lcn')->count())->toBe(1);
});

it('refuse de corriger ou de supprimer la copie depuis la caisse', function (): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amountCents' => 10_000,
            'paidOn' => now()->toDateString(),
            'method' => 'cash',
        ])
        ->assertCreated();

    app(TenantContext::class)->activateCompany($company->id);
    $movement = CashMovement::query()->whereNotNull('payment_id')->firstOrFail();

    // La divergence que la duplication rend possible : corriger la copie sans
    // corriger l'original ferait dire à la caisse autre chose qu'à la facture.
    actingAs($user)
        ->patchJson("/api/v1/cash/movements/{$movement->id}", [
            'occurredAt' => now()->toDateString(),
            'label' => 'Retouche interdite',
            'method' => 'cash',
            'registerName' => 'Caisse principale',
            'amountCents' => 999,
            'currency' => 'MAD',
        ])
        ->assertStatus(409);

    actingAs($user)
        ->deleteJson("/api/v1/cash/movements/{$movement->id}")
        ->assertStatus(409);
});

it('rejoue la synchronisation sans créer de doublon', function (): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    $before = cashTotals($user);

    actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amountCents' => 10_000,
            'paidOn' => now()->toDateString(),
            'method' => 'cash',
        ])
        ->assertCreated();

    // `rebuild()` ALIGNE un état, il ne l'incrémente pas : c'est ce qui permet
    // de le lancer sur tout le stock sans redouter les doublons — et l'index
    // `cash_movements_payment_unique` le garantit en base par-dessus.
    app(TenantContext::class)->runForCompany($company->id, function (): void {
        app(PaymentCashMirror::class)->rebuild();
        app(PaymentCashMirror::class)->rebuild();
    });

    $after = cashTotals($user);

    expect($after['inflow'] - $before['inflow'])->toBe(10_000);
    expect($after['lines'] - $before['lines'])->toBe(1);
});

it('recrée un miroir effacé et retire un miroir orphelin', function (): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    $before = cashTotals($user);

    $orphanId = actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amountCents' => 2_500,
            'paidOn' => now()->toDateString(),
            'method' => 'cash',
        ])
        ->assertCreated()
        ->json('id');

    $keptId = actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amountCents' => 10_000,
            'paidOn' => now()->toDateString(),
            'method' => 'cash',
        ])
        ->assertCreated()
        ->json('id');

    app(TenantContext::class)->runForCompany($company->id, function () use ($orphanId, $keptId): void {
        // Deux dérives symétriques, celles qu'une écriture contournant le
        // service produirait — un import, un script, une requête directe.

        // 1. La copie disparaît sans que le règlement bouge.
        CashMovement::query()->where('payment_id', $keptId)->delete();

        // 2. Le règlement est retiré sans que la copie bouge — un `delete()`
        //    sur le modèle, sans passer par `PaymentWriteService` qui aurait
        //    effacé le miroir avec lui. Le soft delete rend le règlement
        //    invisible à `rebuild()`, dont la copie devient orpheline.
        //
        //    Un `forceDelete` ne conviendrait PAS ici : la clé étrangère est en
        //    `nullOnDelete`, le miroir perdrait son `payment_id` et redeviendrait
        //    une écriture manuelle ordinaire — plus rien à retirer.
        Payment::query()->whereKey($orphanId)->firstOrFail()->delete();

        $result = app(PaymentCashMirror::class)->rebuild();

        // Le règlement vivant est réaligné, le miroir sans règlement est retiré.
        expect($result['synced'])->toBe(1);
        expect($result['removed'])->toBe(1);
    });

    $after = cashTotals($user);

    // Seul le règlement vivant subsiste dans la caisse.
    expect($after['inflow'] - $before['inflow'])->toBe(10_000);
    expect($after['lines'] - $before['lines'])->toBe(1);
});
