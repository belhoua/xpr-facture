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
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Obligatoire pour l'auth par cookies Sanctum (le navigateur doit joindre
    // les cookies aux requêtes cross-origin localhost:3000 → localhost:8080)
    'supports_credentials' => true,

];
