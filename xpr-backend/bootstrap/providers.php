<?php

use App\Modules\Accounting\Providers\AccountingServiceProvider;
use App\Modules\AdminNotes\Providers\AdminNotesServiceProvider;
use App\Modules\Authentication\Providers\AuthenticationServiceProvider;
use App\Modules\Cash\Providers\CashServiceProvider;
use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Dashboard\Providers\DashboardServiceProvider;
use App\Modules\Documents\Providers\DocumentsServiceProvider;
use App\Modules\Partners\Providers\PartnersServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AuthenticationServiceProvider::class,
    TenancyServiceProvider::class,
    AccountingServiceProvider::class,
    PartnersServiceProvider::class,
    CatalogServiceProvider::class,
    DocumentsServiceProvider::class,
    CashServiceProvider::class,
    DashboardServiceProvider::class,
    AdminNotesServiceProvider::class,
];
