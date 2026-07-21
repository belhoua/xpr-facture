<?php

declare(strict_types=1);

use App\Modules\Partners\Models\Partner;
use App\Modules\Tenancy\Services\TenantContext;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/**
 * Répertoire des tiers (Étape B). Les règles qui comptent ici : unicité de
 * l'ICE dans la société, archivage plutôt que suppression, et cloisonnement.
 */

/**
 * Payload minimal valide.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function partnerPayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'client',
        'legalName' => 'Nouvelle Société S.A.R.L.',
        'ice' => '001234567890999',
        'email' => 'contact@nouvelle.ma',
        'city' => 'Casablanca',
        'paymentTermsDays' => 30,
    ], $overrides);
}

it('crée un client', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/partners', partnerPayload())
        ->assertCreated()
        ->assertJsonPath('legalName', 'Nouvelle Société S.A.R.L.')
        ->assertJsonPath('type', 'client')
        ->assertJsonPath('ice', '001234567890999')
        ->assertJsonPath('isActive', true)
        // displayName retombe sur la raison sociale faute d'enseigne
        ->assertJsonPath('displayName', 'Nouvelle Société S.A.R.L.');
});

it('accepte un particulier sans ICE', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/partners', partnerPayload([
            'legalName' => 'Yassine El Amrani',
            'ice' => null,
        ]))
        ->assertCreated()
        ->assertJsonPath('ice', null);
});

it('rejette un ICE qui ne fait pas 15 chiffres', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/partners', partnerPayload(['ice' => '12345']))
        ->assertStatus(422)
        ->assertJsonPath('errors.ice.0', fn (string $message): bool => $message !== '');
});

it('refuse deux tiers avec le même ICE dans une société', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)->postJson('/api/v1/partners', partnerPayload())->assertCreated();

    actingAs($user)
        ->postJson('/api/v1/partners', partnerPayload(['legalName' => 'Autre Raison Sociale']))
        ->assertStatus(422);
});

it('autorise le même ICE dans deux sociétés distinctes', function (): void {
    [$userA] = workspaceAccount();
    [$userB] = workspaceAccount();

    // Deux sociétés peuvent parfaitement avoir le même client.
    actingAs($userA)->postJson('/api/v1/partners', partnerPayload())->assertCreated();
    actingAs($userB)->postJson('/api/v1/partners', partnerPayload())->assertCreated();
});

it('remonte les tiers `both` dans les listes clients ET fournisseurs', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    Partner::factory()->client()->create(['legal_name' => 'Client Pur', 'ice' => null]);
    Partner::factory()->supplier()->create(['legal_name' => 'Fournisseur Pur', 'ice' => null]);
    Partner::factory()->both()->create(['legal_name' => 'Les Deux', 'ice' => null]);

    $clients = actingAs($user)->getJson('/api/v1/partners?type=client')->assertOk()->json('data');
    $suppliers = actingAs($user)->getJson('/api/v1/partners?type=supplier')->assertOk()->json('data');

    $names = static fn (array $rows): array => array_column($rows, 'legalName');

    expect($names($clients))->toContain('Client Pur', 'Les Deux')
        ->and($names($clients))->not->toContain('Fournisseur Pur')
        ->and($names($suppliers))->toContain('Fournisseur Pur', 'Les Deux')
        ->and($names($suppliers))->not->toContain('Client Pur');
});

it('recherche sur la raison sociale, l enseigne et l ICE', function (): void {
    [$user, $company] = workspaceAccount();

    // Libellés volontairement absents du jeu de démo que sème workspaceAccount :
    // le test doit prouver la recherche, pas compter des fiches préexistantes.
    app(TenantContext::class)->activateCompany($company->id);
    Partner::factory()->create([
        'legal_name' => 'Zellige Andalou S.A.R.L.',
        'trade_name' => 'Zellige Andalou',
        'ice' => '009191919191919',
    ]);

    $names = fn (string $term): array => array_column(
        actingAs($user)
            ->getJson('/api/v1/partners?search='.urlencode($term))
            ->assertOk()
            ->json('data'),
        'legalName',
    );

    expect($names('zell'))->toBe(['Zellige Andalou S.A.R.L.'])
        ->and($names('Andalou'))->toBe(['Zellige Andalou S.A.R.L.'])
        ->and($names('009191'))->toBe(['Zellige Andalou S.A.R.L.'])
        ->and($names('introuvable-xyz'))->toBe([]);
});

it('met à jour un tiers sans écraser les champs non transmis', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $partner = Partner::factory()->client()->create([
        'legal_name' => 'Ancienne Raison',
        'email' => 'ancien@exemple.ma',
        'ice' => '003333333333333',
    ]);

    actingAs($user)
        ->patchJson("/api/v1/partners/{$partner->id}", [
            'type' => 'client',
            'legalName' => 'Nouvelle Raison',
        ])
        ->assertOk()
        ->assertJsonPath('legalName', 'Nouvelle Raison')
        // L'e-mail n'était pas dans la requête : il doit survivre.
        ->assertJsonPath('email', 'ancien@exemple.ma');
});

it('laisse un tiers conserver son propre ICE en modification', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $partner = Partner::factory()->create(['ice' => '004444444444444']);

    actingAs($user)
        ->patchJson("/api/v1/partners/{$partner->id}", [
            'type' => 'client',
            'legalName' => 'Raison Modifiée',
            'ice' => '004444444444444',
        ])
        ->assertOk();
});

it('archive au lieu de supprimer', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $partner = Partner::factory()->create(['ice' => '005555555555555']);

    actingAs($user)->deleteJson("/api/v1/partners/{$partner->id}")->assertNoContent();

    app(TenantContext::class)->activateCompany($company->id);

    // Disparu des listes, mais la ligne subsiste : les documents qui le
    // référencent doivent rester lisibles.
    expect(Partner::query()->find($partner->id))->toBeNull()
        ->and(Partner::withTrashed()->find($partner->id))->not->toBeNull();
});

it('libère l ICE d un tiers archivé', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $partner = Partner::factory()->create(['ice' => '006666666666666']);

    actingAs($user)->deleteJson("/api/v1/partners/{$partner->id}")->assertNoContent();

    // L'index unique est partiel : une fiche archivée ne bloque pas son ICE.
    actingAs($user)
        ->postJson('/api/v1/partners', partnerPayload(['ice' => '006666666666666']))
        ->assertCreated();
});

it('isole le répertoire entre deux sociétés', function (): void {
    [$userA, $companyA] = workspaceAccount();
    [$userB, $companyB] = workspaceAccount();

    app(TenantContext::class)->activateCompany($companyA->id);
    $partnerOfA = Partner::factory()->create(['legal_name' => 'Client de A', 'ice' => '007777777777777']);

    app(TenantContext::class)->activateCompany($companyB->id);
    Partner::factory()->create(['legal_name' => 'Client de B', 'ice' => '008888888888888']);

    $namesForB = array_column(
        actingAs($userB)->getJson('/api/v1/partners')->assertOk()->json('data'),
        'legalName',
    );

    expect($namesForB)->toContain('Client de B')
        ->and($namesForB)->not->toContain('Client de A');

    // B ne peut ni lire, ni modifier, ni archiver la fiche de A : 404, et non
    // 403 — l'existence de la ressource ne doit pas fuiter.
    actingAs($userB)->getJson("/api/v1/partners/{$partnerOfA->id}")->assertNotFound();
    actingAs($userB)->patchJson("/api/v1/partners/{$partnerOfA->id}", [
        'type' => 'client',
        'legalName' => 'Détourné',
    ])->assertNotFound();
    actingAs($userB)->deleteJson("/api/v1/partners/{$partnerOfA->id}")->assertNotFound();

    // Et A retrouve sa fiche intacte.
    actingAs($userA)
        ->getJson("/api/v1/partners/{$partnerOfA->id}")
        ->assertOk()
        ->assertJsonPath('legalName', 'Client de A');
});

it('filtre sur les tiers actifs', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    Partner::factory()->create(['legal_name' => 'Fiche Active', 'ice' => null]);
    Partner::factory()->inactive()->create(['legal_name' => 'Fiche Inactive', 'ice' => null]);

    $names = function (string $query) use ($user): array {
        return array_column(
            actingAs($user)->getJson('/api/v1/partners'.$query)->assertOk()->json('data'),
            'legalName',
        );
    };

    // Assertions par appartenance, pas par comptage : le jeu de démo peuple
    // déjà le répertoire, et un total figé casserait au moindre ajout.
    expect($names('?active=1'))->toContain('Fiche Active')
        ->and($names('?active=1'))->not->toContain('Fiche Inactive')
        ->and($names(''))->toContain('Fiche Active', 'Fiche Inactive');
});

it('exige une authentification', function (): void {
    getJson('/api/v1/partners')->assertUnauthorized();
});

it('refuse la création à un lecteur', function (): void {
    [$user, $company] = workspaceAccount();

    // workspaceAccount attribue `owner` : on rétrograde pour ce cas.
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $user->syncRoles(['viewer']);

    actingAs($user)->getJson('/api/v1/partners')->assertOk();
    actingAs($user)->postJson('/api/v1/partners', partnerPayload())->assertForbidden();
});
