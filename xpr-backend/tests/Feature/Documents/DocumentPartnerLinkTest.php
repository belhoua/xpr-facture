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

    // La facture naît ÉMISE depuis le 2026-08-14 : la création suffit à la
    // numéroter, il n'y a plus d'appel à `/issue` à intercaler ici.
    $documentId = (string) actingAs($user)
        ->postJson('/api/v1/documents', linkedDocumentPayload(['partnerId' => $partner->id]))
        ->assertCreated()
        ->assertJsonPath('status', 'sent')
        ->json('id');

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

it('ouvre une fiche client depuis un nom saisi librement', function (): void {
    [$user, $company] = workspaceAccount();

    // Décision du 2026-08-17 : le « client de passage » n'existe plus comme
    // état durable. Un document sans `partner_id` n'apparaît dans aucun écran
    // par client — il porte le bon nom et reste introuvable par son propre
    // client. Le nom libre devient donc une SAISIE RAPIDE de la fiche.
    $document = actingAs($user)
        ->postJson('/api/v1/documents', linkedDocumentPayload(['clientName' => 'Client Occasionnel']))
        ->assertCreated()
        ->assertJsonPath('clientName', 'Client Occasionnel')
        // L'interface doit pouvoir annoncer la fiche née de cette saisie.
        ->assertJsonPath('autoCreatedPartnerName', 'Client Occasionnel')
        ->json();

    expect($document['partnerId'])->not->toBeNull();

    app(TenantContext::class)->activateCompany($company->id);
    /** @var Partner $partner */
    $partner = Partner::query()->whereKey($document['partnerId'])->firstOrFail();

    expect($partner->legal_name)->toBe('Client Occasionnel')
        ->and($partner->type->value)->toBe('client')
        // La fiche naît NUE : aucune mention légale n'est devinée. En inventer
        // une ferait imprimer sur une facture un identifiant que personne n'a
        // vérifié (§3).
        ->and($partner->ice)->toBeNull()
        ->and($partner->address)->toBeNull();
});

it('réutilise la fiche existante plutôt que d en ouvrir une seconde', function (string $typed): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $partner = Partner::factory()->client()->create([
        'legal_name' => 'Comptoir Atlas S.A.R.L.',
        'ice' => null,
    ]);

    // La casse et les espaces de bord ne font pas deux clients. Ce qui n'est
    // PAS rapproché en revanche — « Comptoir Atlas SARL », sans les points —
    // ouvre bien une seconde fiche : un rapprochement approximatif
    // attribuerait les créances d'un client à un autre, ce qui coûte plus cher
    // qu'un doublon fusionné à la main.
    actingAs($user)
        ->postJson('/api/v1/documents', linkedDocumentPayload(['clientName' => $typed]))
        ->assertCreated()
        ->assertJsonPath('partnerId', $partner->id)
        // Retrouvée, pas créée : il n'y a rien à annoncer.
        ->assertJsonPath('autoCreatedPartnerName', null)
        // C'est la FICHE qui fait foi sur l'identité, pas la frappe.
        ->assertJsonPath('clientName', 'Comptoir Atlas S.A.R.L.');

    app(TenantContext::class)->activateCompany($company->id);
    expect(Partner::query()->where('legal_name', 'ilike', 'Comptoir Atlas%')->count())->toBe(1);
})->with([
    'à l’identique' => 'Comptoir Atlas S.A.R.L.',
    'casse différente' => 'comptoir atlas s.a.r.l.',
    'espaces de bord' => '  Comptoir Atlas S.A.R.L.  ',
]);

it('n interroge pas les fiches ARCHIVÉES pour rapprocher un nom', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $archived = Partner::factory()->client()->create([
        'legal_name' => 'Ancien Client S.A.',
        'ice' => null,
    ]);
    $archived->delete();

    // Une fiche rangée l'a été délibérément : la ressusciter au détour d'une
    // facture déciderait à la place de celui qui l'a rangée.
    $partnerId = actingAs($user)
        ->postJson('/api/v1/documents', linkedDocumentPayload(['clientName' => 'Ancien Client S.A.']))
        ->assertCreated()
        ->assertJsonPath('autoCreatedPartnerName', 'Ancien Client S.A.')
        ->json('partnerId');

    expect($partnerId)->not->toBe($archived->id);
});

it('rattache la facture au client de l autre société JAMAIS, même à nom égal', function (): void {
    [, $companyA] = workspaceAccount();
    [$userB, $companyB] = workspaceAccount();

    app(TenantContext::class)->activateCompany($companyA->id);
    $partnerOfA = Partner::factory()->client()->create([
        'legal_name' => 'Homonyme S.A.R.L.',
        'ice' => null,
    ]);

    // Le rapprochement passe par une requête scopée : le tiers d'une autre
    // société est invisible, et B ouvre sa propre fiche (§5).
    $partnerId = actingAs($userB)
        ->postJson('/api/v1/documents', linkedDocumentPayload(['clientName' => 'Homonyme S.A.R.L.']))
        ->assertCreated()
        ->json('partnerId');

    expect($partnerId)->not->toBe($partnerOfA->id);

    app(TenantContext::class)->activateCompany($companyB->id);
    /** @var Partner $created */
    $created = Partner::query()->whereKey($partnerId)->firstOrFail();

    expect($created->company_id)->toBe($companyB->id);
});

it('n ouvre aucune fiche quand un tiers est explicitement choisi', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $partner = Partner::factory()->client()->create(['legal_name' => 'Choisi S.A.', 'ice' => null]);
    $before = Partner::query()->count();

    // `partnerId` transmis fait foi : un `clientName` qui l'accompagne ne doit
    // pas contredire l'identité légale du tiers choisi.
    actingAs($user)
        ->postJson('/api/v1/documents', linkedDocumentPayload([
            'partnerId' => $partner->id,
            'clientName' => 'Nom Contradictoire',
        ]))
        ->assertCreated()
        ->assertJsonPath('partnerId', $partner->id)
        ->assertJsonPath('clientName', 'Choisi S.A.')
        ->assertJsonPath('autoCreatedPartnerName', null);

    app(TenantContext::class)->activateCompany($company->id);
    expect(Partner::query()->count())->toBe($before);
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

    // L'échéance par défaut se pose au moment où le numéro se pose : à la
    // CRÉATION depuis le 2026-08-14, et non plus à l'émission.
    actingAs($user)
        ->postJson('/api/v1/documents', linkedDocumentPayload([
            'partnerId' => $partner->id,
            'issuedAt' => now()->toDateString(),
        ]))
        ->assertCreated()
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
        actingAs($user)
            ->postJson('/api/v1/documents', [
                'type' => 'invoice',
                'partnerId' => $partner->id,
                'issuedAt' => now()->toDateString(),
                'items' => [['label' => 'Prestation', 'quantity' => '1', 'unitPriceCents' => $amount]],
            ])
            ->assertCreated();
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
