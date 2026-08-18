<?php

declare(strict_types=1);

use App\Modules\Authentication\Models\User;
use App\Modules\Tenancy\Enums\Role;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CompanyMemberService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

/**
 * L'écran Utilisateurs (`/users`) et le chargement des RÔLES.
 *
 * Ce que ces cas verrouillent : les rôles sont chargés AVEC la liste des
 * membres, jamais membre par membre. Le service les lisait en lazy load, ce que
 * `Model::preventLazyLoading()` — actif hors production — transformait en
 * exception : l'écran ne ramait pas, il tombait.
 *
 * La suite existante ne l'attrapait pas, et c'est le vrai enseignement :
 * `handleLazyLoadingViolation()` s'abstient quand le modèle porte
 * `wasRecentlyCreated`, ce qui est le cas de tout ce qu'une factory vient de
 * créer dans le même processus. D'où le `refresh()` explicite ci-dessous — sans
 * lui, le test rejouerait la même cécité que celui qu'il complète.
 */

/**
 * Deux membres de rôles distincts dans une société neuve.
 *
 * @return array{0: User, 1: Company}
 */
function membersCompany(): array
{
    $owner = User::factory()->create(['name' => 'Alpha Owner']);
    $company = Company::factory()->create();

    $owner->companies()->attach($company, ['joined_at' => now()]);
    $owner->forceFill(['default_company_id' => $company->id])->save();

    $second = User::factory()->create(['name' => 'Beta Admin']);
    $second->companies()->attach($company, ['joined_at' => now()]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $owner->assignRole(Role::Owner->value);
    $second->assignRole(Role::Admin->value);

    return [$owner, $company];
}

it('sert la liste des membres sans lazy load', function (): void {
    [$owner, $company] = membersCompany();

    // Les modèles fraîchement créés sont EXEMPTÉS de la garde par Laravel. On
    // relit donc la société depuis la base : les membres qu'elle rendra seront
    // des instances neuves, soumises à la règle comme en exploitation.
    app(TenantContext::class)->activateCompany($company->id);
    /** @var Company $reloaded */
    $reloaded = Company::query()->whereKey($company->id)->firstOrFail();

    expect(Model::preventsLazyLoading())->toBeTrue();

    $members = app(CompanyMemberService::class)->listMembers($reloaded);

    expect($members)->toHaveCount(2);
    expect($members->pluck('role')->all())->toBe(['owner', 'admin']);

    // La relation est bien CHARGÉE sur chaque membre : c'est ce qui distingue
    // « ça passe » de « ça passe parce que la garde s'est abstenue ».
    foreach ($members as $member) {
        expect($member['user']->relationLoaded('roles'))->toBeTrue();
    }

    actingAs($owner)->getJson('/api/v1/users')->assertOk();
});

it('rend le rôle propre à CHAQUE société d un même utilisateur', function (): void {
    [$owner] = membersCompany();

    // Le rôle est scopé à la société (mode teams Spatie). L'eager loading doit
    // partir APRÈS `setPermissionsTeamId` : chargé avant, il rendrait les rôles
    // du périmètre précédent — ici ceux de la première société, ou aucun.
    $other = Company::factory()->create();
    $owner->companies()->attach($other, ['joined_at' => now()]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($other->id);
    $owner->assignRole(Role::Viewer->value);

    app(TenantContext::class)->activateCompany($other->id);
    /** @var Company $reloaded */
    $reloaded = Company::query()->whereKey($other->id)->firstOrFail();

    $members = app(CompanyMemberService::class)->listMembers($reloaded);

    expect($members)->toHaveCount(1);
    expect($members->pluck('role')->all())->toBe(['viewer']);
});
