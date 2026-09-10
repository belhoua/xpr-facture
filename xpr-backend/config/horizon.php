<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If verifica-
    | tion is not needed, you may leave this value blank.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | Connexion `redis` de config/database.php — REDIS_CLIENT=predis en
    | production (cf. xpr-infrastructure/.env.prod.example, le Dockerfile
    | FrankenPHP n'embarque pas l'extension phpredis).
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | Isole les clés Horizon des autres clés Redis (cache, sessions) sur la
    | même instance — un seul conteneur `redis` sert les trois en production.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'bcat'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | Le tableau de bord Horizon n'est pas exposé au public : la Gate
    | `viewHorizon` (app/Providers/HorizonServiceProvider::gate()) le
    | restreint aux e-mails listés dans HORIZON_ADMIN_EMAILS. Pas de
    | middleware `auth` classique ici — ce projet n'a aucune route Blade
    | nommée `login` (authentification SPA via Sanctum), donc `auth`
    | déclencherait une RouteNotFoundException à la redirection.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | En secondes, avant que Horizon ne signale une file en souffrance.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Durée de rétention (minutes) des jobs terminés/échoués — purge Redis
    | régulièrement, indispensable sur les 256 Mo alloués au conteneur `redis`.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    */

    'silenced' => [
        // ...
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | Limite PROPRE à Horizon (par process worker), distincte du plafond
    | mémoire du conteneur (384M, cf. docker-compose.prod.yml). 128 Mo par
    | worker PHP est large pour ce qui est traité ici ; Horizon redémarre le
    | worker s'il est dépassé, sans toucher au conteneur.
    |
    */

    'memory_limit' => 128,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | `balance: simple` plutôt que `auto` : avec seulement 2 process au total
    | sur un unique vCPU, l'algorithme d'équilibrage automatique n'a rien à
    | arbitrer et ne fait qu'ajouter une prise de décision périodique inutile.
    | `simple` répartit une bonne fois pour toutes selon `maxProcesses`.
    |
    | maxProcesses = 2 (production) : c'est le plafond demandé pour ne pas
    | saturer le seul vCPU du VPS — le reste du CPU doit rester disponible
    | pour Postgres, Gotenberg (Chromium) et le serveur web FrankenPHP.
    |
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'simple',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 2,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
            ],
        ],
    ],

];
