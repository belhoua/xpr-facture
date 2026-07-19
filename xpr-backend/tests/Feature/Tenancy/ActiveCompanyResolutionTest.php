<?php

declare(strict_types=1);

use App\Modules\Authentication\Models\User;
use App\Modules\Tenancy\Models\Company;

/**
 * Résolution de la société active (CLAUDE.md §5.3) : toujours depuis
 * l'utilisateur authentifié et ses appartenances effectives — jamais depuis
 * un paramètre de requête.
 */
it('privilégie la société par défaut quand elle est une appartenance effective', function (): void {
    $user = User::factory()->create();
    $first = Company::factory()->create();
    $preferred = Company::factory()->create();

    $user->companies()->attach($first, ['joined_at' => now()]);
    $user->companies()->attach($preferred, ['joined_at' => now()]);
    $user->update(['default_company_id' => $preferred->id]);

    expect($user->resolveActiveCompany()?->id)->toBe($preferred->id);
});

it('retombe sur la première appartenance si la préférence n\'est plus valide', function (): void {
    $user = User::factory()->create();
    $member = Company::factory()->create();
    $foreign = Company::factory()->create();

    $user->companies()->attach($member, ['joined_at' => now()]);
    // Préférence pointant vers une société dont l'utilisateur n'est PAS membre :
    // elle ne doit jamais être honorée (sinon un simple update deviendrait une
    // élévation d'accès).
    $user->update(['default_company_id' => $foreign->id]);

    expect($user->resolveActiveCompany()?->id)->toBe($member->id);
});

it('ignore les invitations en attente (joined_at NULL)', function (): void {
    $user = User::factory()->create();
    $pending = Company::factory()->create();

    $user->companies()->attach($pending, ['invited_at' => now()]);

    expect($user->resolveActiveCompany())->toBeNull();
});
