<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée du module Invoices : expose GET /api/v1/invoices.
 */
final class InvoicesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}
