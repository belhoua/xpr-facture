<?php

declare(strict_types=1);

use App\Modules\Authentication\Models\User;
use App\Modules\Tenancy\Models\Company;

use function Pest\Laravel\actingAs;

it('retourne utilisateur, société active et sociétés en un appel (US-6)', function (): void {
    $user = User::factory()->create();
    $main = Company::factory()->create();
    $second = Company::factory()->create();

    $user->companies()->attach($main, ['joined_at' => now()]);
    $user->companies()->attach($second, ['joined_at' => now()]);
    $user->update(['default_company_id' => $main->id]);

    actingAs($user)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('active_company.id', $main->id)
        ->assertJsonCount(2, 'companies');
});

it("n'inclut pas les invitations en attente dans les sociétés", function (): void {
    $user = User::factory()->create();
    $joined = Company::factory()->create();
    $pending = Company::factory()->create();

    $user->companies()->attach($joined, ['joined_at' => now()]);
    $user->companies()->attach($pending, ['invited_at' => now()]); // joined_at NULL

    $response = actingAs($user)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonCount(1, 'companies');

    expect($response->json('companies.0.id'))->toBe($joined->id);
});

it("ne montre jamais les sociétés d'un autre utilisateur (isolation A/B)", function (): void {
    $userA = User::factory()->create();
    $companyA = Company::factory()->create();
    $userA->companies()->attach($companyA, ['joined_at' => now()]);

    $userB = User::factory()->create();
    $companyB = Company::factory()->create();
    $userB->companies()->attach($companyB, ['joined_at' => now()]);

    $companiesSeenByA = actingAs($userA)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->json('companies.*.id');

    // toBe strict : la liste contient EXACTEMENT la société de A, donc
    // l'absence de celle de B est déjà prouvée.
    expect($companiesSeenByA)->toBe([$companyA->id]);
});
