<?php

declare(strict_types=1);

use App\Modules\Authentication\Models\User;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\postJson;

it('connecte un utilisateur avec des identifiants valides', function (): void {
    $user = User::factory()->create(['password' => 'mot-de-passe-solide']);

    postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'mot-de-passe-solide',
    ])->assertOk()->assertJsonPath('user.id', $user->id);

    assertAuthenticatedAs($user);
});

it('répond la MÊME erreur neutre pour mot de passe faux et e-mail inconnu', function (): void {
    $user = User::factory()->create(['password' => 'mot-de-passe-solide']);

    $wrongPassword = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'mauvais-mot-de-passe',
    ])->assertStatus(422)->json('errors.email.0');

    $unknownEmail = postJson('/api/v1/auth/login', [
        'email' => 'inconnu@exemple.ma',
        'password' => 'mauvais-mot-de-passe',
    ])->assertStatus(422)->json('errors.email.0');

    // Anti-énumération : impossible de distinguer les deux cas
    expect($wrongPassword)->toBe($unknownEmail);
    assertGuest();
});

it('refuse la connexion d\'un compte supprimé (soft delete)', function (): void {
    $user = User::factory()->create(['password' => 'mot-de-passe-solide']);
    $user->delete();

    postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'mot-de-passe-solide',
    ])->assertStatus(422);

    assertGuest();
});

it('bloque la 6e tentative dans la minute (429, anti force brute)', function (): void {
    $user = User::factory()->create(['password' => 'mot-de-passe-solide']);

    foreach (range(1, 5) as $i) {
        postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'mauvais-mot-de-passe',
        ])->assertStatus(422);
    }

    postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'mauvais-mot-de-passe',
    ])->assertStatus(429)->assertHeader('Content-Type', 'application/problem+json');
});

it('pose le jeton « rester connecté » sur demande (US-3, 30 jours)', function (): void {
    $user = User::factory()->create([
        'password' => 'mot-de-passe-solide',
        'remember_token' => null,
    ]);

    postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'mot-de-passe-solide',
        'remember' => true,
    ])->assertOk();

    expect($user->fresh()?->remember_token)->not->toBeNull();
});
