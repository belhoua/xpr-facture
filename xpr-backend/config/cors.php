<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     * Restrictif dès le dev (CLAUDE.md §10) : uniquement les frontends déclarés,
     * jamais de joker. FRONTEND_URL accepte PLUSIEURS origines séparées par des
     * virgules — Next bascule sur le port 3001 quand 3000 est déjà pris, et une
     * origine unique transformait cet accident banal en échec d'authentification
     * illisible (« Session store not set on request. »).
     *
     * La première valeur reste l'URL canonique : c'est elle que lisent les
     * redirections et les liens d'e-mails, via config('app.frontend_url').
     */
    /*
     * Origines exactes. `FRONTEND_URL` en tête, complétée par les domaines du
     * déploiement Vercel COURANT — front et back y étant tous deux hébergés,
     * l'URL change à chaque livraison et ne peut pas être reportée à la main.
     *
     * Les variables système de Vercel donnent un hôte SANS schéma : on préfixe
     * en https, seul schéma que Vercel serve.
     */
    'allowed_origins' => array_values(array_unique(array_filter(array_merge(
        array_map('trim', explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000'))),
        array_map(
            // `preg_replace` et non `ltrim` : ce dernier prend une LISTE DE
            // CARACTÈRES, pas un préfixe — il aurait amputé tout hôte commençant
            // par h, t, p ou s (« photo-app.vercel.app » → « oto-app… »).
            static fn (string $host): string => 'https://'.preg_replace('#^https?://#i', '', trim($host)),
            array_filter([
                env('VERCEL_URL'),
                env('VERCEL_BRANCH_URL'),
                env('VERCEL_PROJECT_PRODUCTION_URL'),
            ], 'is_string'),
        ),
    )))),

    /*
     * Origines autorisées par MOTIF, pour les déploiements dont l'URL change à
     * chaque livraison — les previews Vercel, typiquement
     * (`xpr-facture-git-<branche>-<équipe>.vercel.app`), impossibles à lister à
     * la main.
     *
     * Les motifs s'écrivent en langage courant, avec `*` comme joker, et sont
     * traduits en expression régulière ancrée aux deux bouts :
     *
     *     FRONTEND_URL_PATTERNS=https://xpr-facture-*.vercel.app
     *
     * ⚠️ RESTREIGNEZ TOUJOURS LE MOTIF À VOTRE PROJET. `https://*.vercel.app`
     * ferait entrer dans le périmètre de confiance TOUT site déployé sur
     * Vercel, par n'importe qui : combiné à `supports_credentials` ci-dessous,
     * un domaine hostile pourrait émettre des requêtes portant les cookies de
     * session de vos utilisateurs. Le préfixe du projet est ce qui sépare un
     * joker commode d'une porte ouverte.
     *
     * Vide par défaut : le front passe par le proxy Next (`next.config.ts`,
     * rewrites), donc le navigateur reste en même origine et CORS n'entre pas
     * en jeu. Cette liste ne sert qu'aux clients qui appellent l'API en direct.
     */
    'allowed_origins_patterns' => array_values(array_map(
        static fn (string $pattern): string => '#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).'$#i',
        array_filter(array_map(
            'trim',
            explode(',', (string) env('FRONTEND_URL_PATTERNS', '')),
        )),
    )),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Obligatoire pour l'auth par cookies Sanctum (le navigateur doit joindre
    // les cookies aux requêtes cross-origin localhost:3000 → localhost:8080)
    'supports_credentials' => true,

];
