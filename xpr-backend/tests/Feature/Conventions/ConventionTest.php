<?php

declare(strict_types=1);

use App\Modules\Conventions\Models\Convention;
use App\Modules\Documents\Models\Document;
use App\Modules\Partners\Models\Partner;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;

use function Pest\Laravel\actingAs;

/**
 * Contrats de convention de contrôle et suivi.
 *
 * Ce qui est éprouvé ici : le transfert depuis un devis pré-remplit ce qu'il
 * doit et rien de plus, l'échéancier de l'article 10 ne peut pas s'écarter de
 * 100 %, une convention signée ne se supprime pas, et une société ne voit pas
 * les conventions d'une autre (§5.6).
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function conventionPayload(array $overrides = []): array
{
    return array_merge([
        'ownerName' => 'Société Clinique La Vallée',
        'projectDescription' => 'Construction d\'une polyclinique R+1 avec 3 sous-sols',
        'totalCents' => 16_224_000,
    ], $overrides);
}

it('crée une convention avec l\'échéancier par défaut du modèle', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/conventions', conventionPayload())
        ->assertCreated()
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('currency', 'MAD')
        ->assertJsonPath('advancePercent', 25)
        ->assertJsonPath('visaPercent', 25)
        ->assertJsonPath('completionPercent', 50)
        // 25 % / 25 % / 50 % de 162 240,00 DH TTC.
        ->assertJsonPath('instalmentsCents.advance', 4_056_000)
        ->assertJsonPath('instalmentsCents.visa', 4_056_000)
        ->assertJsonPath('instalmentsCents.completion', 8_112_000);
});

it('refuse un échéancier qui ne couvre pas le forfait', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/conventions', conventionPayload([
            'advancePercent' => 30,
            'visaPercent' => 30,
            'completionPercent' => 30,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('advancePercent');
});

it('refuse un échéancier transmis à moitié', function (): void {
    [$user] = workspaceAccount();

    // Sans les deux autres parts, le serveur devrait SUPPOSER ce qu'il ignore.
    actingAs($user)
        ->postJson('/api/v1/conventions', conventionPayload(['advancePercent' => 40]))
        ->assertStatus(422);
});

it('fait absorber l\'arrondi par le solde', function (): void {
    [$user] = workspaceAccount();

    // 1 centime : 25 % n'en font aucun, le solde doit porter le tout — sinon la
    // somme des trois échéances ne ferait pas le montant dû.
    $response = actingAs($user)
        ->postJson('/api/v1/conventions', conventionPayload(['totalCents' => 1]))
        ->assertCreated();

    $instalments = $response->json('instalmentsCents');

    expect($instalments)->toBe(['advance' => 0, 'visa' => 0, 'completion' => 1]);
});

it('transfère un devis en convention en reprenant maître d\'ouvrage, projet et honoraires', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);

    $partner = Partner::factory()->client()->create([
        'company_id' => $company->id,
        'legal_name' => 'Société Clinique La Vallée',
        'rc_number' => '32577',
    ]);

    $quote = Document::factory()->quote()->sent()->create([
        'company_id' => $company->id,
        'partner_id' => $partner->id,
        'client_name' => 'Société Clinique La Vallée',
        'client_ice' => '001234567890123',
        'client_address' => 'Route Amizmiz, Marrakech',
        'subject' => 'Contrôle technique d\'une polyclinique R+1',
        'issue_city' => 'MARRAKECH',
        'total_cents' => 16_224_000,
    ]);

    actingAs($user)
        ->postJson("/api/v1/conventions/from-document/{$quote->id}")
        ->assertCreated()
        ->assertJsonPath('sourceDocumentId', $quote->id)
        ->assertJsonPath('partnerId', $partner->id)
        ->assertJsonPath('ownerName', 'Société Clinique La Vallée')
        ->assertJsonPath('ownerIce', '001234567890123')
        // Le RC ne figure sur aucun document : il vient de la fiche tiers.
        ->assertJsonPath('ownerRc', '32577')
        ->assertJsonPath('projectDescription', 'Contrôle technique d\'une polyclinique R+1')
        ->assertJsonPath('issueCity', 'MARRAKECH')
        ->assertJsonPath('totalCents', 16_224_000)
        ->assertJsonPath('status', 'draft')
        // Les quatre lots du modèle client, à ajuster ensuite au projet.
        ->assertJsonCount(4, 'lots')
        // Le titre foncier n'existe sur aucun document commercial.
        ->assertJsonPath('projectTitleDeed', null);
});

it('laisse le devis intact après le transfert', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $quote = Document::factory()->quote()->sent()->create(['company_id' => $company->id]);

    actingAs($user)
        ->postJson("/api/v1/conventions/from-document/{$quote->id}")
        ->assertCreated();

    // Contrairement à la conversion devis → facture, le transfert ne CONSOMME
    // pas le devis : il reste convertible en facture, ce qui est le cas normal.
    expect($quote->refresh()->status->value)->toBe('sent');
});

it('refuse de fonder une convention sur un document annulé', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $quote = Document::factory()->quote()->cancelled()->create(['company_id' => $company->id]);

    actingAs($user)
        ->postJson("/api/v1/conventions/from-document/{$quote->id}")
        ->assertStatus(409);
});

it('refuse de fonder une convention sur un bon de livraison', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    // Seuls un devis et une facture portent des honoraires convenus : tout
    // autre type est refusé, quel que soit son état.
    $deliveryNote = Document::factory()->sent()->create([
        'company_id' => $company->id,
        'type' => 'delivery_note',
    ]);

    actingAs($user)
        ->postJson("/api/v1/conventions/from-document/{$deliveryNote->id}")
        ->assertStatus(409);
});

it('corrige une convention signée', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $convention = Convention::query()->create(conventionColumns($company->id, ['status' => 'signed']));

    // Le gel du §3 vise les pièces fiscales numérotées, pas un contrat de droit
    // privé : une coquille sur le titre foncier se rectifie avant le dépôt.
    actingAs($user)
        ->patchJson("/api/v1/conventions/{$convention->id}", ['projectTitleDeed' => '138618/04'])
        ->assertOk()
        ->assertJsonPath('projectTitleDeed', '138618/04');
});

it('refuse de supprimer une convention signée', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $convention = Convention::query()->create(conventionColumns($company->id, ['status' => 'signed']));

    actingAs($user)
        ->deleteJson("/api/v1/conventions/{$convention->id}")
        ->assertStatus(409);
});

it('supprime un brouillon de convention', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $convention = Convention::query()->create(conventionColumns($company->id));

    actingAs($user)
        ->deleteJson("/api/v1/conventions/{$convention->id}")
        ->assertNoContent();

    expect(Convention::query()->find($convention->id))->toBeNull();
});

it('ne montre pas les conventions d\'une autre société', function (): void {
    [$user, $company] = workspaceAccount();
    $other = Company::factory()->create();

    app(TenantContext::class)->activateCompany($other->id);
    $foreign = Convention::query()->create(conventionColumns($other->id, [
        'owner_name' => 'Maître d\'ouvrage de la société B',
    ]));

    app(TenantContext::class)->activateCompany($company->id);
    Convention::query()->create(conventionColumns($company->id));

    $response = actingAs($user)->getJson('/api/v1/conventions')->assertOk();

    expect($response->json('meta.total'))->toBe(1);

    // Et l'accès direct par identifiant ne contourne pas le cloisonnement.
    actingAs($user)->getJson("/api/v1/conventions/{$foreign->id}")->assertNotFound();
});
