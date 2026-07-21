<?php

use App\Modules\Authentication\Providers\AuthenticationServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AuthenticationServiceProvider::class,
    TenancyServiceProvider::class,
];
