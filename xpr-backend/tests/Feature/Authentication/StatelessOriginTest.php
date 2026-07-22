<?php

declare(strict_types=1);

use App\Modules\Authentication\Models\User;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;
use function Pest\Laravel\withHeaders;

/**
 * Une origine absente de SANCTUM_STATEFUL_DOMAINS ne reçoit pas la pile de
 * session : le guard `web` heurte alors une session inexistante et Laravel
 * lève « Session store not set on request. ».
 *
 * Ce cas est arrivé en vrai : Next bascule sur le port 3001 quand 3000 est
 * occupé, et le message brut ne désignait pas sa cause. Ces tests verrouillent
 * le diagnostic rendu à l'appelant — et, tout aussi important, le fait qu'il ne
 * déborde pas sur les autres erreurs 500.
 *
 * Note : `Tests\TestCase::setUp()` pose un Referer stateful pour tous les
 * tests ; chaque cas ci-dessous l'écrase délibérément.
 */
it('explique qu\'une origine non déclarée n\'a pas de session, au lieu du message brut de Laravel', function (): void {
    $user = User::factory()->create(['password' => 'mot-de-passe-solide']);

    $response = withHeader('Referer', 'http://localhost:9999/fr/login')
        ->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'mot-de-passe-solide',
        ]);

    // 500 assumé : c'est le DÉPLOIEMENT qui est mal configuré, pas la requête.
    // Un 4xx ferait croire à l'appelant qu'il peut la reformuler.
    $response->assertStatus(500)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('title', __('Session authentication is not available for this origin'));

    // L'origine fautive est nommée : c'est elle qu'on doit aller déclarer.
    expect((string) $response->json('detail'))
        ->toContain('http://localhost:9999/fr/login');
});

it('signale l\'absence totale de Referer et d\'Origin', function (): void {
    $user = User::factory()->create(['password' => 'mot-de-passe-solide']);

    // Referer vidé : sans lui ni Origin, Sanctum ne peut identifier aucune
    // origine — cas d'un client HTTP qui n'imite pas un navigateur.
    $response = withHeaders(['Referer' => null, 'Origin' => null])
        ->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'mot-de-passe-solide',
        ]);

    $response->assertStatus(500)
        ->assertJsonPath('title', __('Session authentication is not available for this origin'))
        ->assertJsonPath('detail', __('The request carried neither Referer nor Origin, so no session was started. Both are required for cookie-based authentication.'));
});

it('masque la configuration hors mode debug', function (): void {
    config()->set('app.debug', false);

    $user = User::factory()->create(['password' => 'mot-de-passe-solide']);

    $response = withHeader('Referer', 'http://localhost:9999/fr/login')
        ->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'mot-de-passe-solide',
        ]);

    // Ni le nom de la variable d'environnement, ni l'origine : en production,
    // le détail d'exploitation appartient aux logs, pas à la réponse HTTP.
    $response->assertStatus(500)
        ->assertJsonPath('detail', __('This origin is not allowed to use session authentication.'));

    $detail = (string) $response->json('detail');

    expect($detail)->not->toContain('SANCTUM_STATEFUL_DOMAINS');
    expect($detail)->not->toContain('localhost:9999');
});

it('laisse passer une origine déclarée stateful', function (): void {
    $user = User::factory()->create(['password' => 'mot-de-passe-solide']);

    // Garde anti-régression : la détection ne doit pas s'appliquer au flux
    // normal, celui que suit le SPA.
    postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'mot-de-passe-solide',
    ])->assertOk()->assertJsonPath('user.id', $user->id);
});
