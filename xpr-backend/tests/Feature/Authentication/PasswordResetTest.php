<?php

declare(strict_types=1);

use App\Modules\Authentication\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

use function Pest\Laravel\postJson;

it('envoie le lien et répond la même chose pour un compte inconnu (anti-énumération)', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $known = postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
        ->assertOk()->json('message');

    $unknown = postJson('/api/v1/auth/forgot-password', ['email' => 'inconnu@exemple.ma'])
        ->assertOk()->json('message');

    expect($known)->toBe($unknown);
    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertCount(1); // rien n'est parti pour l'e-mail inconnu
});

it('réinitialise le mot de passe avec un token valide et invalide les sessions longues', function (): void {
    $user = User::factory()->create(['password' => 'ancien-mot-de-passe']);
    $token = Password::createToken($user);

    postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'nouveau-mot-de-passe',
    ])->assertOk();

    $fresh = $user->fresh();
    expect(Hash::check('nouveau-mot-de-passe', (string) $fresh?->password))->toBeTrue()
        ->and(Hash::check('ancien-mot-de-passe', (string) $fresh?->password))->toBeFalse();
});

it('refuse un token déjà consommé (usage unique)', function (): void {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'nouveau-mot-de-passe',
    ])->assertOk();

    postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'encore-un-autre',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['token']]);
});
