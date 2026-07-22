<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Documents\Models\Document;
use App\Modules\Tenancy\Services\TenantContext;

use function Pest\Laravel\actingAs;

/**
 * Le moteur proprement dit : lignes rattachées au catalogue ou saisies
 * librement, TVA par ligne, récapitulatif par taux, conversion devis → facture
 * et création d'avoir.
 */
it('hérite du catalogue le libellé, l unité, le prix et le taux', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    /** @var Product $product */
    $product = Product::query()->where('reference', 'CONS-J')->firstOrFail();

    $response = actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'clientName' => 'Client Test',
            // Seuls l'article et la quantité sont transmis : c'est exactement
            // ce que fait l'interface quand on choisit une ligne au catalogue.
            'items' => [['productId' => $product->id, 'quantity' => '2']],
        ])
        ->assertCreated();

    $response
        ->assertJsonPath('items.0.label', 'Journée de conseil')
        ->assertJsonPath('items.0.unit', 'jour')
        ->assertJsonPath('items.0.unitPriceCents', 450_000)
        ->assertJsonPath('items.0.taxRate', '20.00')
        // 2 × 4 500,00 = 9 000,00 HT → TVA 1 800,00 → TTC 10 800,00
        ->assertJsonPath('subtotalCents', 900_000)
        ->assertJsonPath('taxCents', 180_000)
        ->assertJsonPath('totalCents', 1_080_000);
});

it('accepte une ligne en saisie libre, sans article', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'clientName' => 'Client Test',
            'items' => [[
                'label' => 'Prestation ponctuelle',
                'quantity' => '1',
                'unitPriceCents' => 100_000,
                'taxRateId' => taxRateId('20.00'),
            ]],
        ])
        ->assertCreated()
        ->assertJsonPath('items.0.productId', null)
        ->assertJsonPath('items.0.label', 'Prestation ponctuelle')
        ->assertJsonPath('taxCents', 20_000);
});

it('laisse la valeur saisie primer sur celle du catalogue', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    /** @var Product $product */
    $product = Product::query()->where('reference', 'CONS-J')->firstOrFail();

    // Un prix négocié doit être possible sans dupliquer l'article au catalogue.
    actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'clientName' => 'Client Négocié',
            'items' => [[
                'productId' => $product->id,
                'quantity' => '1',
                'unitPriceCents' => 400_000,
                'label' => 'Journée de conseil (tarif partenaire)',
            ]],
        ])
        ->assertCreated()
        ->assertJsonPath('items.0.unitPriceCents', 400_000)
        ->assertJsonPath('items.0.label', 'Journée de conseil (tarif partenaire)');
});

it('résout le taux depuis le référentiel et ignore un taux forgé', function (): void {
    [$user] = workspaceAccount();

    // §10 : le frontend ne protège rien. Poster « taxRate: 0 » sur une ligne
    // dont l'identifiant de taux vaut 20 % fabriquerait une facture fausse.
    actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'clientName' => 'Client Test',
            'items' => [[
                'label' => 'Prestation',
                'quantity' => '1',
                'unitPriceCents' => 100_000,
                'taxRateId' => taxRateId('20.00'),
                'taxRate' => '0.00',
            ]],
        ])
        ->assertCreated()
        ->assertJsonPath('items.0.taxRate', '20.00')
        ->assertJsonPath('taxCents', 20_000);
});

it('ventile la TVA par taux en pied de document', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'clientName' => 'Client Multi-taux',
            'items' => [
                ['label' => 'Conseil', 'quantity' => '1', 'unitPriceCents' => 100_000, 'taxRateId' => taxRateId('20.00')],
                ['label' => 'Hébergement', 'quantity' => '1', 'unitPriceCents' => 50_000, 'taxRateId' => taxRateId('10.00')],
                ['label' => 'Conseil (suite)', 'quantity' => '1', 'unitPriceCents' => 100_000, 'taxRateId' => taxRateId('20.00')],
            ],
        ])
        ->assertCreated()
        // Mention obligatoire (§3) : une ligne par taux, taux croissant.
        ->assertJsonPath('taxSummary', [
            ['rate' => '10.00', 'baseCents' => 50_000, 'taxCents' => 5_000],
            ['rate' => '20.00', 'baseCents' => 200_000, 'taxCents' => 40_000],
        ]);
});

it('applique la remise de ligne avant la TVA', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'clientName' => 'Client Remisé',
            'items' => [[
                'label' => 'Prestation',
                'quantity' => '1',
                'unitPriceCents' => 100_000,
                'discountPercent' => '10.00',
                'taxRateId' => taxRateId('20.00'),
            ]],
        ])
        ->assertCreated()
        ->assertJsonPath('discountCents', 10_000)
        ->assertJsonPath('subtotalCents', 90_000)
        ->assertJsonPath('taxCents', 18_000)
        ->assertJsonPath('totalCents', 108_000);
});

it('refuse un article appartenant à une autre société', function (): void {
    [, $companyA] = workspaceAccount();
    [$userB] = workspaceAccount();

    app(TenantContext::class)->activateCompany($companyA->id);
    /** @var Product $productOfA */
    $productOfA = Product::query()->firstOrFail();

    $errors = actingAs($userB)
        ->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'clientName' => 'Intrusion',
            'items' => [['productId' => $productOfA->id, 'quantity' => '1']],
        ])
        ->assertStatus(422)
        ->json('errors');

    // La clé d'erreur contient elle-même des points (`items.0.productId`) :
    // `assertJsonPath` les interpréterait comme une descente dans le tableau
    // et ne trouverait rien. On inspecte donc la table d'erreurs directement.
    expect($errors)->toHaveKey('items.0.productId');
});

// ── Conversion devis → facture ────────────────────────────────────────────

it('convertit un devis en facture brouillon en recopiant ses lignes', function (): void {
    [$user] = workspaceAccount();
    $quote = Document::query()->where('type', 'quote')->firstOrFail();

    $invoice = actingAs($user)
        ->postJson("/api/v1/documents/{$quote->id}/convert")
        ->assertCreated()
        ->assertJsonPath('type', 'invoice')
        // La conversion PROPOSE, elle n'émet pas : pas de numéro consommé.
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('number', null)
        ->assertJsonPath('parentDocumentId', $quote->id)
        ->assertJsonPath('totalCents', $quote->total_cents)
        ->json();

    expect($invoice['items'])->toHaveCount($quote->items()->count());

    // Le devis est consommé : état terminal, plus aucune transition.
    expect($quote->refresh()->status->value)->toBe('converted');
});

it('refuse de convertir deux fois le même devis', function (): void {
    [$user] = workspaceAccount();
    $quote = Document::query()->where('type', 'quote')->firstOrFail();

    actingAs($user)->postJson("/api/v1/documents/{$quote->id}/convert")->assertCreated();
    actingAs($user)->postJson("/api/v1/documents/{$quote->id}/convert")->assertStatus(409);
});

it('refuse de convertir un devis encore en brouillon', function (): void {
    [$user] = workspaceAccount();

    $id = actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'quote',
            'clientName' => 'Prospect',
            'items' => [['label' => 'Étude', 'quantity' => '1', 'unitPriceCents' => 500_000]],
        ])
        ->json('id');

    // Facturer une proposition jamais envoyée, c'est réclamer un paiement sur
    // un document que le client n'a pas vu.
    actingAs($user)->postJson("/api/v1/documents/{$id}/convert")->assertStatus(409);
});

it('refuse de convertir une facture', function (): void {
    [$user] = workspaceAccount();
    $invoice = Document::query()->where('type', 'invoice')->where('status', 'sent')->firstOrFail();

    actingAs($user)->postJson("/api/v1/documents/{$invoice->id}/convert")->assertStatus(409);
});

it('numérote la facture issue d un devis dans la séquence des FACTURES', function (): void {
    [$user] = workspaceAccount();
    $quote = Document::query()->where('type', 'quote')->firstOrFail();
    $year = now()->format('Y');

    $id = actingAs($user)->postJson("/api/v1/documents/{$quote->id}/convert")->json('id');

    // Le devis portait DEV-…, la facture prend FAC-… : chaque type a sa
    // séquence, la clé étant (société, type, exercice).
    actingAs($user)
        ->postJson("/api/v1/documents/{$id}/issue")
        ->assertOk()
        ->assertJsonPath('number', "FAC-{$year}-0007");
});

// ── Avoirs ────────────────────────────────────────────────────────────────

it('crée un avoir brouillon rattaché à la facture d origine', function (): void {
    [$user] = workspaceAccount();
    $invoice = Document::query()->where('type', 'invoice')->where('status', 'sent')->firstOrFail();

    actingAs($user)
        ->postJson("/api/v1/documents/{$invoice->id}/credit-note")
        ->assertCreated()
        ->assertJsonPath('type', 'credit_note')
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('parentDocumentId', $invoice->id)
        // Montants POSITIFS : le sens vient du type, pas d'un signe.
        ->assertJsonPath('totalCents', $invoice->total_cents);
});

it('ne modifie pas la facture d origine lors de la création d un avoir', function (): void {
    [$user] = workspaceAccount();
    $invoice = Document::query()->where('type', 'invoice')->where('status', 'sent')->firstOrFail();
    $before = $invoice->only(['status', 'number', 'total_cents']);

    actingAs($user)->postJson("/api/v1/documents/{$invoice->id}/credit-note")->assertCreated();

    // §3 : une facture émise est immuable. L'avoir s'impute dessus, il ne la
    // corrige pas.
    expect($invoice->refresh()->only(['status', 'number', 'total_cents']))->toBe($before);
});

it('refuse un avoir sur une facture jamais émise', function (): void {
    [$user] = workspaceAccount();
    $draft = Document::query()->where('type', 'invoice')->where('status', 'draft')->firstOrFail();

    // Sans numéro, la facture n'existe pas fiscalement : il n'y a rien à
    // corriger, le brouillon se modifie directement.
    actingAs($user)->postJson("/api/v1/documents/{$draft->id}/credit-note")->assertStatus(409);
});

it('numérote l avoir dans sa propre séquence', function (): void {
    [$user] = workspaceAccount();
    $invoice = Document::query()->where('type', 'invoice')->where('status', 'sent')->firstOrFail();
    $year = now()->format('Y');

    $id = actingAs($user)->postJson("/api/v1/documents/{$invoice->id}/credit-note")->json('id');

    actingAs($user)
        ->postJson("/api/v1/documents/{$id}/issue")
        ->assertOk()
        ->assertJsonPath('number', "AV-{$year}-0001");
});
