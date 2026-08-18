<?php

declare(strict_types=1);

use App\Modules\Documents\Models\Document;
use App\Modules\Partners\Models\Partner;
use App\Modules\Tenancy\Services\TenantContext;

use function Pest\Laravel\actingAs;

/**
 * Réécriture du NUMÉRO d'une pièce déjà numérotée — facture et devis
 * seulement, depuis le 2026-08-18 et à la demande de l'exploitant.
 *
 * Ce que ces cas verrouillent : la levée est réelle, mais bornée. Elle ne vaut
 * que pour deux types, jamais sur un brouillon, jamais jusqu'à vider le champ,
 * et l'unicité par société continue de s'appliquer — c'est le seul garde-fou
 * qui subsiste sur ce champ.
 *
 * Le coût de cette levée est écrit dans `DocumentType::allowsNumberEdit()`.
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function renumberablePayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'invoice',
        'clientName' => 'Client Renumérotation',
        'items' => [['label' => 'Prestation', 'quantity' => '1', 'unitPriceCents' => 400_000]],
    ], $overrides);
}

it('réécrit le numéro d une facture', function (): void {
    [$user, $company] = workspaceAccount();

    $created = actingAs($user)
        ->postJson('/api/v1/documents', renumberablePayload())
        ->assertCreated()
        ->json();

    expect($created['number'])->not->toBeNull();

    actingAs($user)
        ->patchJson("/api/v1/documents/{$created['id']}", ['number' => 'FAC-2026-0777'])
        ->assertOk()
        ->assertJsonPath('number', 'FAC-2026-0777');

    app(TenantContext::class)->activateCompany($company->id);
    /** @var Document $document */
    $document = Document::query()->whereKey($created['id'])->firstOrFail();

    expect($document->number)->toBe('FAC-2026-0777');
});

it('réécrit le numéro d un devis', function (): void {
    [$user] = workspaceAccount();

    $id = actingAs($user)
        ->postJson('/api/v1/documents', renumberablePayload(['type' => 'quote']))
        ->assertCreated()
        ->json('id');

    actingAs($user)
        ->patchJson("/api/v1/documents/{$id}", ['number' => 'DEV-2026-0055'])
        ->assertOk()
        ->assertJsonPath('number', 'DEV-2026-0055');
});

it('laisse le numéro INTACT quand le PATCH ne le porte pas', function (): void {
    [$user] = workspaceAccount();

    $created = actingAs($user)
        ->postJson('/api/v1/documents', renumberablePayload())
        ->assertCreated()
        ->json();

    // La clé absente ne touche à rien : corriger une note ne renumérote pas.
    actingAs($user)
        ->patchJson("/api/v1/documents/{$created['id']}", ['notes' => 'Note corrigée'])
        ->assertOk()
        ->assertJsonPath('number', $created['number']);
});

it('accepte de réenregistrer un document avec SON PROPRE numéro', function (): void {
    [$user] = workspaceAccount();

    $created = actingAs($user)
        ->postJson('/api/v1/documents', renumberablePayload())
        ->assertCreated()
        ->json();

    // Le cas de tous les jours : on ouvre le formulaire, on change une ligne et
    // on enregistre — le numéro repart tel quel. Sans `ignore()` sur la règle
    // d'unicité, la pièce serait rejetée pour son propre compte.
    actingAs($user)
        ->patchJson("/api/v1/documents/{$created['id']}", [
            'number' => $created['number'],
            'notes' => 'Inchangé',
        ])
        ->assertOk()
        ->assertJsonPath('number', $created['number']);
});

// ── Ce que la levée NE permet PAS ────────────────────────────────────────

it('refuse un numéro déjà porté par une autre pièce', function (): void {
    [$user] = workspaceAccount();

    $first = actingAs($user)
        ->postJson('/api/v1/documents', renumberablePayload())
        ->assertCreated()
        ->json();

    $second = actingAs($user)
        ->postJson('/api/v1/documents', renumberablePayload())
        ->assertCreated()
        ->json('id');

    // 422 et non 500 : le doublon est une faute de saisie sur un champ précis,
    // que l'écran doit pouvoir afficher sous le bon libellé.
    actingAs($user)
        ->patchJson("/api/v1/documents/{$second}", ['number' => $first['number']])
        ->assertStatus(422)
        ->assertJsonPath('errors.number.0', fn (string $message): bool => $message !== '');
});

it('refuse de VIDER le numéro d une pièce émise', function (?string $empty): void {
    [$user] = workspaceAccount();

    $created = actingAs($user)
        ->postJson('/api/v1/documents', renumberablePayload())
        ->assertCreated()
        ->json();

    // Rendre le numéro à null le libérerait pour une autre pièce, alors que
    // celle-ci continue de circuler avec le numéro déjà imprimé.
    actingAs($user)
        ->patchJson("/api/v1/documents/{$created['id']}", ['number' => $empty])
        ->assertStatus(422);

    app(TenantContext::class)->activateCompany($created['companyId'] ?? '');
})->with(['null' => null, 'chaîne vide' => '']);

it('refuse la renumérotation sur une SITUATION', function (): void {
    [$user, $company] = workspaceAccount();

    // Client créé ICI et non par un helper d'un autre fichier : Pest n'inclut
    // un fichier de test qu'au moment de l'exécuter, et la fonction manquerait
    // selon l'ordre de passage (cf. la note de tests/Pest.php).
    app(TenantContext::class)->activateCompany($company->id);
    $client = Partner::factory()->client()->create(['ice' => null]);

    $created = actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'situation',
            'partnerId' => $client->id,
            'subject' => 'Situation n°1',
            'totalCents' => 500_000,
            'paidCents' => 0,
        ])
        ->assertCreated()
        ->json();

    // Le périmètre de la levée est le plus étroit possible : deux types, pas
    // trois. `prohibited` plutôt qu'un champ ignoré — un refus silencieux
    // laisserait croire que le numéro a été pris.
    actingAs($user)
        ->patchJson("/api/v1/documents/{$created['id']}", ['number' => 'SIT-2026-0099'])
        ->assertStatus(422)
        ->assertJsonPath('errors.number.0', fn (string $message): bool => $message !== '');

    app(TenantContext::class)->activateCompany($company->id);
    /** @var Document $document */
    $document = Document::query()->whereKey($created['id'])->firstOrFail();

    expect($document->number)->toBe($created['number']);
});

it('refuse un numéro au format aberrant', function (string $number): void {
    [$user] = workspaceAccount();

    $id = actingAs($user)
        ->postJson('/api/v1/documents', renumberablePayload())
        ->assertCreated()
        ->json('id');

    actingAs($user)
        ->patchJson("/api/v1/documents/{$id}", ['number' => $number])
        ->assertStatus(422)
        ->assertJsonPath('errors.number.0', fn (string $message): bool => $message !== '');
})->with([
    'espaces internes' => 'FAC 2026 0001',
    'caractères interdits' => 'FAC<2026>0001',
    'commence par un séparateur' => '-FAC-2026',
    'trop long' => 'FAC-2026-000000000000000000000000000000',
]);

it('n autorise pas une société à heurter le numéro d une AUTRE', function (): void {
    [$userA, $companyA] = workspaceAccount();
    [$userB] = workspaceAccount();

    $ofA = actingAs($userA)
        ->postJson('/api/v1/documents', renumberablePayload())
        ->assertCreated()
        ->json('number');

    $ofB = actingAs($userB)
        ->postJson('/api/v1/documents', renumberablePayload())
        ->assertCreated()
        ->json('id');

    // L'unicité est PAR SOCIÉTÉ : B peut légitimement porter le même numéro
    // que A, chacun tenant sa propre séquence (§3).
    actingAs($userB)
        ->patchJson("/api/v1/documents/{$ofB}", ['number' => $ofA])
        ->assertOk()
        ->assertJsonPath('number', $ofA);

    app(TenantContext::class)->activateCompany($companyA->id);
    expect(Document::query()->where('number', $ofA)->count())->toBe(1);
});

it('rend un numéro libéré réutilisable par une autre pièce', function (): void {
    [$user] = workspaceAccount();

    $first = actingAs($user)
        ->postJson('/api/v1/documents', renumberablePayload())
        ->assertCreated()
        ->json();

    $second = actingAs($user)
        ->postJson('/api/v1/documents', renumberablePayload())
        ->assertCreated()
        ->json('id');

    // La première libère son numéro…
    actingAs($user)
        ->patchJson("/api/v1/documents/{$first['id']}", ['number' => 'FAC-2026-0900'])
        ->assertOk();

    // … et la seconde peut le prendre. C'est exactement la RÉUTILISATION que
    // l'article 145 du CGI proscrit : le produit ne l'empêche plus, la levée
    // du 2026-08-18 l'assume (cf. DocumentType::allowsNumberEdit()).
    actingAs($user)
        ->patchJson("/api/v1/documents/{$second}", ['number' => $first['number']])
        ->assertOk()
        ->assertJsonPath('number', $first['number']);
});
