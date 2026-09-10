<?php

use App\Modules\Accounting\Providers\AccountingServiceProvider;
use App\Modules\AdminNotes\Providers\AdminNotesServiceProvider;
use App\Modules\Authentication\Providers\AuthenticationServiceProvider;
use App\Modules\Cash\Providers\CashServiceProvider;
use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Conventions\Providers\ConventionsServiceProvider;
use App\Modules\Dashboard\Providers\DashboardServiceProvider;
use App\Modules\Documents\Providers\DocumentsServiceProvider;
use App\Modules\Partners\Providers\PartnersServiceProvider;
use App\Modules\Payments\Providers\PaymentsServiceProvider;
use App\Modules\Projects\Providers\ProjectsServiceProvider;
use App\Modules\Services\Providers\ServicesServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    // Package Horizon absent en environnement sans Redis (Vercel/Render
    // gratuit) : ce provider ne fait rien de dangereux dans ce cas, mais
    // n'a de sens qu'avec QUEUE_CONNECTION=redis (cf. VPS prod).
    HorizonServiceProvider::class,
    AuthenticationServiceProvider::class,
    TenancyServiceProvider::class,
    AccountingServiceProvider::class,
    PartnersServiceProvider::class,
    CatalogServiceProvider::class,
    DocumentsServiceProvider::class,
    // Après Documents : le transfert devis → convention s'appuie sur son
    // DocumentService.
    ConventionsServiceProvider::class,
    // Après Partners : un projet exige un client de la société active.
    ProjectsServiceProvider::class,
    ServicesServiceProvider::class,
    CashServiceProvider::class,
    PaymentsServiceProvider::class,
    DashboardServiceProvider::class,
    AdminNotesServiceProvider::class,
];
