<?php

declare(strict_types=1);

use App\Modules\Authentication\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\postJson;

it('déconnecte et invalide la session côté serveur', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson('/api/v1/auth/logout')
        ->assertNoContent();

    // Guard 'web' explicitement : c'est lui qui porte la session invalidée
    // (le guard sanctum de la requête de test garde un cache local).
    assertGuest('web');
});

it('exige une authentification (401 problem+json)', function (): void {
    postJson('/api/v1/auth/logout')
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json');
});
