<?php

declare(strict_types=1);

use App\Modules\Conventions\Models\Convention;
use App\Modules\Conventions\Models\FileDeposit;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;

use function Pest\Laravel\actingAs;

/**
 * Dépôts de dossier auprès des organismes instructeurs.
 *
 * Ce qui est éprouvé ici : le premier dépôt donne son numéro à la convention et
 * les suivants ne l'écrasent pas, une date de décision ne survit pas à un statut
 * non tranché, et un dépôt ne peut pas être rattaché à la convention d'une autre
 * société (§5.6).
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function depositPayload(array $overrides = []): array
{
    return array_merge([
        'reference' => '0003439/AK/26',
        'depositedAt' => '2026-07-22',
        'organisation' => 'Commune de Marrakech',
    ], $overrides);
}

it('enregistre un dépôt et reporte sa référence sur la convention', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $convention = Convention::query()->create(conventionColumns($company->id));

    actingAs($user)
        ->postJson("/api/v1/conventions/{$convention->id}/deposits", depositPayload())
        ->assertCreated()
        ->assertJsonPath('status', 'deposited')
        ->assertJsonPath('reference', '0003439/AK/26')
        ->assertJsonPath('convention.id', $convention->id);

    // Le n° de dossier du contrat vient du guichet : le saisir une seconde fois
    // sur la convention produirait deux numéros pour un seul dossier.
    expect($convention->refresh()->dossier_number)->toBe('0003439/AK/26');
});

it('ne réécrit pas le n° de dossier au second dépôt', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $convention = Convention::query()->create(conventionColumns($company->id));

    actingAs($user)
        ->postJson("/api/v1/conventions/{$convention->id}/deposits", depositPayload())
        ->assertCreated();

    // Un rejet suivi d'un nouveau dépôt donne une nouvelle référence de
    // récépissé — mais le dossier que le contrat cite reste le premier.
    actingAs($user)
        ->postJson("/api/v1/conventions/{$convention->id}/deposits", depositPayload([
            'reference' => '0004120/AK/26',
            'depositedAt' => '2026-09-03',
        ]))
        ->assertCreated();

    expect($convention->refresh()->dossier_number)->toBe('0003439/AK/26');
});

it('efface la date de décision quand le dossier repasse en instruction', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $convention = Convention::query()->create(conventionColumns($company->id));

    $deposit = actingAs($user)
        ->postJson("/api/v1/conventions/{$convention->id}/deposits", depositPayload([
            'status' => 'validated',
            'decidedAt' => '2026-08-14',
        ]))
        ->assertCreated()
        ->assertJsonPath('decidedAt', '2026-08-14');

    /** @var string $id */
    $id = $deposit->json('id');

    // « En cours » ne date rien : laisser la date afficherait « validé le … »
    // sous un statut qui dit l'inverse.
    actingAs($user)
        ->patchJson("/api/v1/deposits/{$id}", depositPayload(['status' => 'in_progress']))
        ->assertOk()
        ->assertJsonPath('status', 'in_progress')
        ->assertJsonPath('decidedAt', null);
});

it('refuse une décision antérieure au dépôt', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $convention = Convention::query()->create(conventionColumns($company->id));

    actingAs($user)
        ->postJson("/api/v1/conventions/{$convention->id}/deposits", depositPayload([
            'status' => 'validated',
            'decidedAt' => '2026-07-01',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('decidedAt');
});

it('filtre les dépôts par convention', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $first = Convention::query()->create(conventionColumns($company->id));
    $second = Convention::query()->create(conventionColumns($company->id));

    actingAs($user)
        ->postJson("/api/v1/conventions/{$first->id}/deposits", depositPayload())
        ->assertCreated();
    actingAs($user)
        ->postJson("/api/v1/conventions/{$second->id}/deposits", depositPayload([
            'reference' => '0004120/AK/26',
        ]))
        ->assertCreated();

    $response = actingAs($user)
        ->getJson("/api/v1/deposits?conventionId={$first->id}")
        ->assertOk();

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.reference'))->toBe('0003439/AK/26');
});

it('ne rattache pas un dépôt à la convention d\'une autre société', function (): void {
    [$user, $company] = workspaceAccount();
    $other = Company::factory()->create();

    app(TenantContext::class)->activateCompany($other->id);
    $foreign = Convention::query()->create(conventionColumns($other->id));

    app(TenantContext::class)->activateCompany($company->id);

    // La convention est résolue SOUS le scope tenant : hors société, elle
    // n'existe pas — 404, et pas un dépôt créé chez le voisin.
    actingAs($user)
        ->postJson("/api/v1/conventions/{$foreign->id}/deposits", depositPayload())
        ->assertNotFound();

    expect(FileDeposit::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('ne montre pas les dépôts d\'une autre société', function (): void {
    [$user, $company] = workspaceAccount();
    $other = Company::factory()->create();

    app(TenantContext::class)->activateCompany($other->id);
    $foreign = Convention::query()->create(conventionColumns($other->id));
    $foreignDeposit = FileDeposit::query()->create([
        'company_id' => $other->id,
        'convention_id' => $foreign->id,
        'reference' => '0009999/AK/26',
        'deposited_at' => '2026-07-22',
        'organisation' => 'Commune de Rabat',
    ]);

    app(TenantContext::class)->activateCompany($company->id);

    $response = actingAs($user)->getJson('/api/v1/deposits')->assertOk();

    expect($response->json('meta.total'))->toBe(0);

    actingAs($user)->getJson("/api/v1/deposits/{$foreignDeposit->id}")->assertNotFound();
});
