<?php

declare(strict_types=1);

use App\Modules\Accounting\Services\CompanyAccountingProvisioning;
use App\Modules\Authentication\Models\User;
use App\Modules\Tenancy\Enums\Permission;
use App\Modules\Tenancy\Enums\Role;
use App\Modules\Tenancy\Models\Company;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

/**
 * RBAC en mode teams (P0-10) : un rôle est détenu DANS une société, pas dans
 * l'absolu. Le point sensible est que Spatie ne sait quel périmètre interroger
 * que si SetTenantContext lui a posé le company_id.
 */

/** Rattache un utilisateur à une société avec un rôle donné. */
function memberOf(User $user, Company $company, Role $role, bool $default = false): void
{
    $user->companies()->attach($company->id, ['joined_at' => now()]);

    $registrar = app(PermissionRegistrar::class);
    $previous = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($company->id);

    try {
        $user->assignRole($role->value);
    } finally {
        $registrar->setPermissionsTeamId($previous);
    }

    if ($default) {
        $user->forceFill(['default_company_id' => $company->id])->save();
    }
}

function companyWithAccounting(): Company
{
    $company = Company::factory()->create();
    app(CompanyAccountingProvisioning::class)->initialize($company);

    return $company;
}

it('accorde les droits du rôle détenu dans la société active', function (): void {
    $user = User::factory()->create();
    $company = companyWithAccounting();
    memberOf($user, $company, Role::Owner, default: true);

    actingAs($user)->getJson('/api/v1/invoices')->assertOk();
    actingAs($user)->getJson('/api/v1/users')->assertOk();
});

it('n exporte PAS un rôle d une société vers une autre', function (): void {
    $user = User::factory()->create();
    $companyA = companyWithAccounting();
    $companyB = companyWithAccounting();

    // Owner chez A, simple lecteur chez B. La société active est B.
    memberOf($user, $companyA, Role::Owner);
    memberOf($user, $companyB, Role::Viewer, default: true);

    // Lecture : accordée par le rôle viewer
    actingAs($user)->getJson('/api/v1/invoices')->assertOk();

    // Écriture : refusée. Si le team id fuyait, le rôle owner détenu chez A
    // autoriserait la création chez B — c'est exactement le bug recherché.
    actingAs($user)
        ->postJson('/api/v1/invoices', [
            'clientName' => 'Client Interdit',
            'issuedAt' => null,
            'dueAt' => null,
            'status' => 'draft',
            'totalCents' => 100000,
            'currency' => 'MAD',
        ])
        ->assertForbidden();
});

it('refuse à un commercial d annuler une facture', function (): void {
    $user = User::factory()->create();
    $company = companyWithAccounting();
    memberOf($user, $company, Role::Sales, default: true);

    // Création autorisée…
    $invoice = actingAs($user)
        ->postJson('/api/v1/invoices', [
            'clientName' => 'Client Commercial',
            'issuedAt' => now()->toDateString(),
            'dueAt' => now()->addMonth()->toDateString(),
            'status' => 'sent',
            'totalCents' => 250000,
            'currency' => 'MAD',
        ])
        ->assertCreated()
        ->json('id');

    // … mais l'annulation est un acte fiscal réservé (§3)
    actingAs($user)->postJson("/api/v1/invoices/{$invoice}/cancel")->assertForbidden();
    actingAs($user)->deleteJson("/api/v1/invoices/{$invoice}")->assertForbidden();
});

it('cantonne un lecteur à la lecture', function (): void {
    $user = User::factory()->create();
    $company = companyWithAccounting();
    memberOf($user, $company, Role::Viewer, default: true);

    actingAs($user)->getJson('/api/v1/dashboard/stats')->assertOk();
    actingAs($user)->getJson('/api/v1/cash/movements')->assertOk();

    actingAs($user)
        ->postJson('/api/v1/cash/movements', [
            'occurredAt' => now()->toDateString(),
            'label' => 'Tentative',
            'method' => 'cash',
            'registerName' => 'Caisse principale',
            'amountCents' => 10000,
            'currency' => 'MAD',
        ])
        ->assertForbidden();

    actingAs($user)->postJson('/api/v1/users/invitations', [
        'email' => 'nouveau@exemple.ma',
        'role' => 'viewer',
    ])->assertForbidden();
});

it('réserve la modification des paramètres au propriétaire', function (): void {
    // L'identité légale engage la société : l'admin ne la modifie pas.
    expect(Role::Admin->permissionValues())->not->toContain(Permission::SettingsUpdate->value)
        ->and(Role::Owner->permissionValues())->toContain(Permission::SettingsUpdate->value)
        // …mais l'admin garde tout le reste
        ->and(Role::Admin->permissionValues())->toContain(Permission::UsersInvite->value)
        ->and(Role::Admin->permissionValues())->toContain(Permission::InvoicesCancel->value);
});

it('ne laisse aucune permission orpheline', function (): void {
    $granted = collect(Role::cases())
        ->flatMap(fn (Role $role): array => $role->permissionValues())
        ->unique();

    // Une permission que personne ne détient est soit un oubli dans la matrice,
    // soit une route inaccessible : dans les deux cas, un bug.
    expect($granted->diff(Permission::values())->all())->toBe([])
        ->and(collect(Permission::values())->diff($granted)->all())->toBe([]);
});
