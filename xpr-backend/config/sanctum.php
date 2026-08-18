<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    /*
     * ── Domaines auxquels Sanctum ouvre une SESSION ───────────────────────
     *
     * Front et back sont déployés sur Vercel, où l'URL change à chaque
     * livraison : `xpr-facture-git-<branche>-<équipe>.vercel.app`. Les lister à
     * la main est impossible, d'où les deux sources ci-dessous.
     *
     * 1. `SANCTUM_STATEFUL_DOMAINS`, qui accepte le joker. C'est là que se
     *    déclare le domaine du FRONT — celui que le navigateur affiche, et donc
     *    l'`Origin` que Sanctum reçoit à travers le proxy Next.
     *
     * 2. Les domaines du déploiement COURANT, lus dans les variables système
     *    que Vercel injecte. Ils couvrent l'accès direct au backend sans qu'on
     *    ait à les reporter quelque part à chaque preview.
     *
     * ⚠️ POURQUOI PAS « *.vercel.app » : le domaine est MUTUALISÉ entre tous
     * les clients de Vercel. Le joker nu ferait de n'importe quel site qu'un
     * tiers y déploie une origine à laquelle Sanctum ouvre une session ; avec
     * `supports_credentials` activé côté CORS, cela revient à offrir le CSRF.
     * Le préfixe du projet est ce qui sépare le joker commode de la porte
     * ouverte — d'où `xpr-facture-*.vercel.app` et non `*.vercel.app`.
     *
     * Pour la même raison, `Sanctum::currentRequestHost()` reste commenté : il
     * rendrait stateful l'origine de l'appelant, quelle qu'elle soit.
     */
    'stateful' => array_values(array_unique(array_filter(array_merge(
        array_map('trim', explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', sprintf(
            '%s%s',
            'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
            Sanctum::currentApplicationUrlWithPort(),
            // Sanctum::currentRequestHost(),
        )))),
        // Variables système Vercel : hôtes SANS schéma, exactement la forme
        // attendue ici. Absentes hors de Vercel, où le filtre les écarte.
        array_map('trim', array_filter([
            env('VERCEL_URL'),
            env('VERCEL_BRANCH_URL'),
            env('VERCEL_PROJECT_PRODUCTION_URL'),
        ], 'is_string')),
    )))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
