<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Charge le fichier autoload de Composer dans xpr-backend/vendor/
require __DIR__ . '/../vendor/autoload.php';

// Initialise Laravel depuis xpr-backend/bootstrap/
$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$response = $kernel->handle(
    $request = Request::capture()
)->send();

$kernel->terminate($request, $response);