<?php

use App\Modules\Authentication\Providers\AuthenticationServiceProvider;
use App\Modules\Cash\Providers\CashServiceProvider;
use App\Modules\Dashboard\Providers\DashboardServiceProvider;
use App\Modules\Invoices\Providers\InvoicesServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AuthenticationServiceProvider::class,
    TenancyServiceProvider::class,
    InvoicesServiceProvider::class,
    CashServiceProvider::class,
    DashboardServiceProvider::class,
];
