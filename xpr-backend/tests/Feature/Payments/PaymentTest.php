<?php

declare(strict_types=1);

use App\Modules\Authentication\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Payments\Models\Payment;
use App\Modules\Tenancy\Enums\Role;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

/**
 * Règlements reçus sur une facture.
 *
 * La règle centrale éprouvée ici : `documents.paid_cents` et le statut de la
 * facture sont DÉRIVÉS des règlements vivants, jamais saisis. Chaque écriture
 * doit les laisser cohérents — c'est ce qui garantit qu'un badge ne contredit
 * jamais l'historique affiché juste en dessous.
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function paymentPayload(array $overrides = []): array
{
    return array_merge([
        'amountCents' => 50_000,
        'paidOn' => now()->toDateString(),
        'method' => 'cash',
    ], $overrides);
}

it('enregistre un règlement et passe la facture en partiellement payée', function (): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", paymentPayload())
        ->assertCreated()
        ->assertJsonPath('amountCents', 50_000)
        ->assertJsonPath('method', 'cash');

    app(TenantContext::class)->activateCompany($company->id);
    $invoice->refresh();

    expect($invoice->paid_cents)->toBe(50_000)
        ->and($invoice->status->value)->toBe('partial');
});

it('passe la facture en payée quand le cumul atteint le total', function (): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);
    $total = $invoice->total_cents;

    actingAs($user)->postJson(
        "/api/v1/invoices/{$invoice->id}/payments",
        paymentPayload(['amountCents' => $total]),
    )->assertCreated();

    app(TenantContext::class)->activateCompany($company->id);

    expect($invoice->refresh()->status->value)->toBe('paid');
});

it('additionne plusieurs règlements', function (): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/payments", paymentPayload(['amountCents' => 30_000]));
    actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/payments", paymentPayload(['amountCents' => 20_000]));

    app(TenantContext::class)->activateCompany($company->id);

    expect($invoice->refresh()->paid_cents)->toBe(50_000);
});

it('ramène la facture à envoyée quand le dernier règlement est retiré', function (): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    $paymentId = actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", paymentPayload())
        ->json('id');

    actingAs($user)->deleteJson("/api/v1/payments/{$paymentId}")->assertNoContent();

    app(TenantContext::class)->activateCompany($company->id);
    $invoice->refresh();

    expect($invoice->paid_cents)->toBe(0)
        ->and($invoice->status->value)->toBe('sent');

    // Soft delete : la ligne demeure. Un encaissement retiré a existé, et sa
    // trace est ce qui permet d'expliquer un cumul passé.
    expect(Payment::query()->find($paymentId))->toBeNull();
    expect(Payment::query()->withTrashed()->find($paymentId))->not->toBeNull();
});

it('expose l historique et les cumuls de la facture', function (): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/payments", paymentPayload(['amountCents' => 40_000]));

    actingAs($user)
        ->getJson("/api/v1/invoices/{$invoice->id}/payments")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('summary.totalCents', $invoice->total_cents)
        ->assertJsonPath('summary.paidCents', 40_000)
        ->assertJsonPath('summary.remainingCents', $invoice->total_cents - 40_000);
});

// ── Ce que le module REFUSE ───────────────────────────────────────────────

it('refuse un règlement sur un devis', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $quote = Document::query()->where('type', 'quote')->firstOrFail();

    // Une proposition commerciale n'ouvre aucune créance : il n'y a rien à
    // solder tant qu'elle n'est pas devenue facture.
    actingAs($user)
        ->postJson("/api/v1/invoices/{$quote->id}/payments", paymentPayload())
        ->assertStatus(409);
});

it('refuse un règlement sur une facture annulée', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $cancelled = Document::query()
        ->where('type', 'invoice')
        ->where('status', 'cancelled')
        ->firstOrFail();

    actingAs($user)
        ->postJson("/api/v1/invoices/{$cancelled->id}/payments", paymentPayload())
        ->assertStatus(409);
});

it('refuse un règlement sur un brouillon', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $draft = Document::factory()->draft()->create(['company_id' => $company->id]);

    // Sans numéro, la facture n'existe pas fiscalement : l'encaissement serait
    // rattaché à une pièce que rien n'atteste.
    actingAs($user)
        ->postJson("/api/v1/invoices/{$draft->id}/payments", paymentPayload())
        ->assertStatus(409);
});

it('refuse un montant nul ou négatif', function (int $amount): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", paymentPayload(['amountCents' => $amount]))
        ->assertStatus(422)
        ->assertJsonPath('errors.amountCents.0', fn (string $m): bool => $m !== '');
})->with([0, -1000]);

// ── Effets bancaires : chèque et LCN ──────────────────────────────────────

it('exige le numéro et le statut sur un chèque', function (string $method): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    $errors = actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", paymentPayload(['method' => $method]))
        ->assertStatus(422)
        ->json('errors');

    expect($errors)->toHaveKeys(['checkNumber', 'checkStatus']);
})->with(['cheque', 'lcn']);

it('enregistre un chèque avec ses dates et son statut', function (): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", paymentPayload([
            'method' => 'cheque',
            'checkNumber' => '4412907',
            'checkStatus' => 'pending',
            'receivedDate' => now()->subDays(3)->toDateString(),
            'bankDepositDate' => now()->toDateString(),
        ]))
        ->assertCreated()
        ->assertJsonPath('checkNumber', '4412907')
        ->assertJsonPath('checkStatus', 'pending');
});

it('refuse une remise en banque antérieure à la réception', function (): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    // On ne dépose pas un titre qu'on n'a pas encore reçu.
    actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", paymentPayload([
            'method' => 'cheque',
            'checkNumber' => '4412907',
            'checkStatus' => 'pending',
            'receivedDate' => now()->toDateString(),
            'bankDepositDate' => now()->subDays(2)->toDateString(),
        ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.receivedDate.0', fn (string $m): bool => $m !== '');
});

it('ignore les champs d effet sur un mode qui n en porte pas', function (): void {
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    // Le numéro transmis sur des espèces n'est pas rejeté mais IGNORÉ : la
    // contrainte `payments_check_fields_check` le refuserait en base, et un 500
    // sur un champ surnuméraire serait une mauvaise réponse.
    actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", paymentPayload([
            'method' => 'cash',
            'checkNumber' => '4412907',
            'checkStatus' => 'pending',
        ]))
        ->assertCreated()
        ->assertJsonPath('checkNumber', null)
        ->assertJsonPath('checkStatus', null);
});

it('n accepte un scan que sur un effet bancaire', function (): void {
    Storage::fake('local');
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    actingAs($user)
        ->post("/api/v1/invoices/{$invoice->id}/payments", paymentPayload([
            'method' => 'cash',
            'scan' => UploadedFile::fake()->create('cheque.pdf', 120, 'application/pdf'),
        ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.scan.0', fn (string $m): bool => $m !== '');
});

it('stocke le scan hors webroot et le sert sous son nom d origine', function (): void {
    Storage::fake('local');
    [$user, $company] = workspaceAccount();
    $invoice = payableInvoice($company->id);

    $payment = actingAs($user)
        ->post("/api/v1/invoices/{$invoice->id}/payments", paymentPayload([
            'method' => 'cheque',
            'checkNumber' => '4412907',
            'checkStatus' => 'pending',
            'scan' => UploadedFile::fake()->create('remise banque.pdf', 120, 'application/pdf'),
        ]))
        ->assertCreated()
        ->json();

    expect($payment['scanName'])->toBe('remise banque.pdf')
        ->and($payment['scanUrl'])->toContain("/payments/{$payment['id']}/scan");

    // Le fichier est écrit sous un nom ALÉATOIRE, dans le dossier de la
    // société : le nom d'origine ne doit jamais servir de clé.
    $stored = Storage::disk('local')->allFiles("payments/{$company->id}");

    expect($stored)->toHaveCount(1)
        ->and($stored[0])->not->toContain('remise banque');

    actingAs($user)->get("/api/v1/payments/{$payment['id']}/scan")->assertOk();
});

// ── Isolation et permissions ──────────────────────────────────────────────

it('ne laisse pas une société voir les règlements d une autre', function (): void {
    [$userA] = workspaceAccount();
    [$userB, $companyB] = workspaceAccount();
    $invoiceOfB = payableInvoice($companyB->id);

    $paymentOfB = actingAs($userB)
        ->postJson("/api/v1/invoices/{$invoiceOfB->id}/payments", paymentPayload())
        ->json('id');

    app(TenantContext::class)->forget();

    // 404 et non 403 : l'existence même de la ressource ne doit pas fuiter (§5).
    actingAs($userA)->getJson("/api/v1/invoices/{$invoiceOfB->id}/payments")->assertNotFound();
    actingAs($userA)->postJson("/api/v1/invoices/{$invoiceOfB->id}/payments", paymentPayload())->assertNotFound();
    actingAs($userA)->deleteJson("/api/v1/payments/{$paymentOfB}")->assertNotFound();
    actingAs($userA)->getJson("/api/v1/payments/{$paymentOfB}/scan")->assertNotFound();
});

it('cantonne un lecteur à la consultation des règlements', function (): void {
    $user = User::factory()->create();
    $company = companyWithAccounting();
    memberOf($user, $company, Role::Viewer, default: true);

    app(TenantContext::class)->activateCompany($company->id);
    $invoice = Document::factory()->sent()->create(['company_id' => $company->id]);

    actingAs($user)->getJson("/api/v1/invoices/{$invoice->id}/payments")->assertOk();
    actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", paymentPayload())
        ->assertForbidden();
});
