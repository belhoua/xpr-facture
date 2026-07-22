<?php

declare(strict_types=1);

use App\Modules\Documents\Models\Document;
use App\Modules\Partners\Models\Partner;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\QueryException;

use function Pest\Laravel\actingAs;

/**
 * Rattachement document → tiers.
 *
 * La règle centrale : `partner_id` sert à AGRÉGER, `client_name` à RESTITUER
 * le document. Le second est figé à l'émission et ne suit jamais un renommage
 * du premier (§3, immuabilité).
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function linkedDocumentPayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'invoice',
        'items' => [['label' => 'Prestation', 'quantity' => '1', 'unitPriceCents' => 450_000]],
    ], $overrides);
}

it('fige la raison sociale et l ICE du tiers sur le document', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $partner = Partner::factory()->client()->create([
        'legal_name' => 'Comptoir Atlas S.A.R.L.',
        // L'enseigne ne doit PAS être retenue : le document engage l'entité
        // légale, pas le nom commercial.
        'trade_name' => 'Comptoir',
        'ice' => '001234567890777',
    ]);

    actingAs($user)
        ->postJson('/api/v1/documents', linkedDocumentPayload(['partnerId' => $partner->id]))
        ->assertCreated()
        ->assertJsonPath('partnerId', $partner->id)
        ->assertJsonPath('clientName', 'Comptoir Atlas S.A.R.L.')
        ->assertJsonPath('clientIce', '001234567890777');
});

it('ne réécrit pas les documents émis quand le tiers est renommé', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $partner = Partner::factory()->client()->create([
        'legal_name' => 'Ancienne Raison S.A.R.L.',
        'ice' => null,
    ]);

    $documentId = (string) actingAs($user)
        ->postJson('/api/v1/documents', linkedDocumentPayload(['partnerId' => $partner->id]))
        ->assertCreated()
        ->json('id');

    actingAs($user)->postJson("/api/v1/documents/{$documentId}/issue")->assertOk();

    // Le tiers change de raison sociale…
    actingAs($user)
        ->patchJson("/api/v1/partners/{$partner->id}", [
            'type' => 'client',
            'legalName' => 'Nouvelle Raison S.A.',
        ])
        ->assertOk();

    // … le document DÉJÀ ÉMIS garde le nom qu'il portait. C'est ce qui a été
    // envoyé au client : le modifier réécrirait un document fiscal.
    app(TenantContext::class)->activateCompany($company->id);
    $document = Document::query()->findOrFail($documentId);

    expect($document->client_name)->toBe('Ancienne Raison S.A.R.L.')
        ->and($document->partner_id)->toBe($partner->id)
        // Le lien reste exploitable pour les agrégats.
        ->and($document->partner?->legal_name)->toBe('Nouvelle Raison S.A.');
});

it('accepte un client de passage sans tiers', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/documents', linkedDocumentPayload(['clientName' => 'Client Occasionnel']))
        ->assertCreated()
        ->assertJsonPath('partnerId', null)
        ->assertJsonPath('clientName', 'Client Occasionnel');
});

it('exige un nom quand aucun tiers n est fourni', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/documents', linkedDocumentPayload())
        ->assertStatus(422)
        ->assertJsonPath('errors.clientName.0', fn (string $m): bool => $m !== '');
});

it('refuse le tiers d une autre société', function (): void {
    [, $companyA] = workspaceAccount();
    [$userB] = workspaceAccount();

    app(TenantContext::class)->activateCompany($companyA->id);
    $partnerOfA = Partner::factory()->client()->create(['ice' => null]);

    // B tente de rattacher son document au client de A : la règle `exists` est
    // scopée à la société active, donc 422 — pas de fuite inter-sociétés.
    actingAs($userB)
        ->postJson('/api/v1/documents', linkedDocumentPayload(['partnerId' => $partnerOfA->id]))
        ->assertStatus(422)
        ->assertJsonPath('errors.partnerId.0', fn (string $m): bool => $m !== '');
});

it('calcule l échéance depuis le délai de règlement du tiers', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $partner = Partner::factory()->client()->create([
        'payment_terms_days' => 45,
        'ice' => null,
    ]);

    $id = actingAs($user)
        ->postJson('/api/v1/documents', linkedDocumentPayload([
            'partnerId' => $partner->id,
            'issuedAt' => now()->toDateString(),
        ]))
        ->json('id');

    actingAs($user)
        ->postJson("/api/v1/documents/{$id}/issue")
        ->assertOk()
        ->assertJsonPath('dueAt', now()->addDays(45)->toDateString());
});

it('empêche la suppression dure d un tiers référencé par un document', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $partner = Partner::factory()->client()->create(['ice' => null]);

    actingAs($user)
        ->postJson('/api/v1/documents', linkedDocumentPayload(['partnerId' => $partner->id]))
        ->assertCreated();

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
    Document::query()->delete();
    $partner = Partner::factory()->client()->create([
        'legal_name' => 'Groupement Test S.A.',
        'ice' => null,
    ]);

    $issue = function (int $amount) use ($user, $partner): void {
        $id = actingAs($user)
            ->postJson('/api/v1/documents', [
                'type' => 'invoice',
                'partnerId' => $partner->id,
                'issuedAt' => now()->toDateString(),
                'items' => [['label' => 'Prestation', 'quantity' => '1', 'unitPriceCents' => $amount]],
            ])
            ->assertCreated()
            ->json('id');

        actingAs($user)->postJson("/api/v1/documents/{$id}/issue")->assertOk();
    };

    $issue(100_000);
    $issue(250_000);

    // Le tiers est renommé entre deux factures : sans regroupement par
    // `partner_id`, les documents apparaîtraient comme plusieurs clients.
    actingAs($user)->patchJson("/api/v1/partners/{$partner->id}", [
        'type' => 'client',
        'legalName' => 'Groupement Renommé S.A.',
    ])->assertOk();

    $issue(50_000);

    $top = actingAs($user)->getJson('/api/v1/dashboard/stats')->assertOk()->json('topClients');

    expect($top)->toHaveCount(1)
        ->and($top[0]['partnerId'])->toBe($partner->id)
        ->and($top[0]['invoiceCount'])->toBe(3)
        ->and($top[0]['totalCents'])->toBe(400_000);
});
