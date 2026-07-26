<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Remonte d'un niveau pour cibler vendor dans xpr-backend
require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$response = $kernel->handle(
    $request = Request::capture()
)->send();

$kernel->terminate($request, $response);