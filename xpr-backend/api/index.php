<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Point d'entrée serverless (Vercel)
|--------------------------------------------------------------------------
|
| Équivalent de public/index.php, adapté aux deux contraintes du serverless :
|
|  1. Le paquet déployé est en LECTURE SEULE. Laravel écrit pourtant pendant
|     une requête (sessions de secours, manifeste de services, vues compilées).
|     Seul /tmp est inscriptible : on y déporte storage/ et bootstrap/cache/.
|
|  2. /tmp est propre à chaque instance et vidé au démarrage à froid. Rien de
|     durable ne doit y être stocké — d'où SESSION_DRIVER et CACHE_STORE sur
|     PostgreSQL, et FILESYSTEM_DISK qui ne sert qu'à des fichiers jetables.
|
| public/index.php reste le point d'entrée du développement local (`make up`)
| et de toute exécution en conteneur : ce fichier ne le remplace pas.
|
*/

define('LARAVEL_START', microtime(true));

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$writable = '/tmp/xpr';

// Recréée à chaque démarrage à froid — d'où l'idempotence.
foreach ([
    $writable.'/storage/app',
    $writable.'/storage/framework/cache/data',
    $writable.'/storage/framework/sessions',
    $writable.'/storage/framework/views',
    $writable.'/storage/logs',
    $writable.'/bootstrap/cache',
] as $directory) {
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
}

// Manifestes produits au build par `composer install` (post-autoload-dump →
// artisan package:discover). Les recopier dans /tmp évite que Laravel tente
// de les régénérer dans bootstrap/cache, qui est en lecture seule.
//
// Le manifeste des paquets est résolu paresseusement (le conteneur enregistre
// PackageManifest via une closure) : la redirection ci-dessous s'applique donc
// bien, à condition qu'elle précède la première résolution — c'est-à-dire
// handleRequest().
foreach (['packages.php', 'services.php'] as $manifest) {
    $source = __DIR__.'/../bootstrap/cache/'.$manifest;
    $target = $writable.'/bootstrap/cache/'.$manifest;

    if (is_file($source) && ! is_file($target)) {
        copy($source, $target);
    }
}

$app->useStoragePath($writable.'/storage');
$app->useBootstrapPath($writable.'/bootstrap');

/*
| Neutralisation du répertoire de base déduit du nom du script.
|
| Symfony reconstruit l'URL de base à partir de SCRIPT_NAME. Ce fichier vivant
| dans api/, SCRIPT_NAME vaut « /api/index.php » : son répertoire parent
| « /api » est alors pris pour un préfixe d'installation et RETIRÉ du chemin.
| Une requête sur /api/v1/documents est cherchée comme « v1/documents » et
| renvoie 404 — tandis que /up, qui ne commence pas par /api, fonctionne. Le
| symptôme est donc une API entièrement en 404 alors que la sonde de santé
| répond, ce qui envoie chercher le problème du mauvais côté.
|
| Déclarer le script à la racine remet le répertoire de base à la chaîne vide :
| le chemin est transmis intact au routeur.
*/
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

$app->handleRequest(Request::capture());
