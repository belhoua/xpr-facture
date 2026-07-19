<?php

declare(strict_types=1);

use App\Modules\Authentication\Models\User;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\postJson;

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function registerPayload(array $overrides = []): array
{
    return [
        'name' => 'Othmane Alami',
        'email' => 'othmane@exemple.ma',
        'password' => 'mot-de-passe-solide',
        'company_legal_name' => 'Atlas Conseil SARL',
        'company_legal_form' => 'sarl',
        'locale' => 'fr',
        ...$overrides,
    ];
}

it('inscrit compte + société + rôle owner en une seule transaction', function (): void {
    Notification::fake();

    $response = postJson('/api/v1/auth/register', registerPayload());

    $response->assertCreated()
        ->assertJsonPath('user.email', 'othmane@exemple.ma')
        ->assertJsonPath('company.legal_name', 'Atlas Conseil SARL')
        ->assertJsonPath('company.vat_exempt', false);

    $user = User::where('email', 'othmane@exemple.ma')->firstOrFail();
    $company = Company::where('legal_name', 'Atlas Conseil SARL')->firstOrFail();

    // Appartenance effective (pas une invitation en attente)
    expect($user->companies()->whereNotNull('joined_at')->pluck('companies.id')->all())
        ->toBe([$company->id])
        ->and($user->default_company_id)->toBe($company->id);

    // Rôle owner scopé à CETTE société (mode teams Spatie)
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    expect($user->hasRole('owner'))->toBeTrue();

    // Connecté immédiatement (cookie de session posé)
    assertAuthenticatedAs($user);

    // E-mail de vérification parti (non bloquant, arbitrage Q1)
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('active la mention TVA non applicable pour un auto-entrepreneur', function (): void {
    Notification::fake();

    postJson('/api/v1/auth/register', registerPayload([
        'company_legal_form' => 'auto_entrepreneur',
        'company_legal_name' => 'Yassine Prestations',
    ]))->assertCreated()->assertJsonPath('company.vat_exempt', true);

    expect(Company::firstOrFail()->vat_exempt)->toBeTrue();
});

it('rejette un e-mail déjà utilisé, au format problem+json', function (): void {
    User::factory()->create(['email' => 'othmane@exemple.ma']);

    postJson('/api/v1/auth/register', registerPayload())
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 422)
        ->assertJsonStructure(['title', 'status', 'errors' => ['email']]);
});

it("n'enregistre AUCUN compte si la création de la société échoue (rollback)", function (): void {
    // Échec RÉEL en fin de provisioning (pas un mock, interdit sur classe
    // final) : sans rôle owner, assignRole lève une exception après que
    // user + société + pivot ont déjà été insérés dans la transaction.
    Role::query()->where('name', 'owner')->delete();

    postJson('/api/v1/auth/register', registerPayload())->assertStatus(500);

    // La transaction a tout annulé : pas de compte orphelin
    expect(User::count())->toBe(0)->and(Company::count())->toBe(0);
});

it("autorise la réinscription d'un e-mail dont le compte a été supprimé", function (): void {
    Notification::fake();

    $old = User::factory()->create(['email' => 'othmane@exemple.ma']);
    $old->delete(); // soft delete : l'index partiel libère l'e-mail

    postJson('/api/v1/auth/register', registerPayload())->assertCreated();

    expect(User::withTrashed()->where('email', 'othmane@exemple.ma')->count())->toBe(2);
});
