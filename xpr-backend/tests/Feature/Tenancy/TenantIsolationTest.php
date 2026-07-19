<?php

declare(strict_types=1);

use App\Modules\Shared\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Exceptions\TenantContextMissing;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixture : table tenant minimale pour éprouver le trait sans dépendre d'un
 * module métier (le premier arrive en Phase 1). Créée dans la transaction de
 * test, elle disparaît au rollback de RefreshDatabase.
 *
 * Ce test couvre la PREMIÈRE ligne de défense (scope Eloquent). La seconde
 * (RLS, connexion xpr_app non-superuser) a son propre harnais en P0-09 :
 * ici la connexion de test est superuser, la RLS ne s'applique pas à elle.
 *
 * @property string $company_id
 */
final class TenantFixture extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $table = 'tenant_fixtures';

    protected $fillable = ['name', 'company_id'];
}

/** @return array{TenantContext, Company, Company} */
function tenantFixtures(): array
{
    Schema::create('tenant_fixtures', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->foreignUuid('company_id')->constrained('companies');
        $table->string('name');
        $table->timestamps();
    });

    return [
        app(TenantContext::class),
        Company::factory()->create(['legal_name' => 'Société A']),
        Company::factory()->create(['legal_name' => 'Société B']),
    ];
}

it('refuse de créer une entité tenant sans société active', function (): void {
    tenantFixtures();

    TenantFixture::create(['name' => 'orpheline']);
})->throws(TenantContextMissing::class);

it('attribue automatiquement le company_id de la société active', function (): void {
    [$context, $companyA] = tenantFixtures();

    $context->activateCompany($companyA->id);

    $fixture = TenantFixture::create(['name' => 'document A']);

    expect($fixture->company_id)->toBe($companyA->id);
});

it('empêche la société A de voir les données de la société B', function (): void {
    [$context, $companyA, $companyB] = tenantFixtures();

    $context->activateCompany($companyA->id);
    TenantFixture::create(['name' => 'document A']);

    $context->activateCompany($companyB->id);
    TenantFixture::create(['name' => 'document B']);

    expect(TenantFixture::pluck('name')->all())->toBe(['document B']);

    $context->activateCompany($companyA->id);

    expect(TenantFixture::pluck('name')->all())->toBe(['document A']);
});

it('interdit de forcer une lecture hors de la société active par where', function (): void {
    [$context, $companyA, $companyB] = tenantFixtures();

    $context->activateCompany($companyA->id);
    TenantFixture::create(['name' => 'document A']);

    $context->activateCompany($companyB->id);

    // Même en ciblant explicitement la société A, le scope global s'ajoute
    // en AND : aucune fuite possible par construction de requête.
    $leaked = TenantFixture::query()
        ->where('company_id', $companyA->id)
        ->count();

    expect($leaked)->toBe(0);
});

it('ne filtre plus après forget, et refuse à nouveau la création', function (): void {
    [$context, $companyA] = tenantFixtures();

    $context->activateCompany($companyA->id);
    TenantFixture::create(['name' => 'document A']);

    $context->forget();

    // Sans contexte, le scope applicatif est neutre : c'est la RLS qui prend
    // le relais en production (connexion xpr_app). La création, elle, reste
    // impossible : pas de company_id à attribuer.
    expect(TenantFixture::count())->toBe(1)
        ->and(fn () => TenantFixture::create(['name' => 'orpheline']))
        ->toThrow(TenantContextMissing::class);
});
