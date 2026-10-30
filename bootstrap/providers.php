<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\GuestExtrasServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\OrgServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    GuestExtrasServiceProvider::class,
    OrgServiceProvider::class,
];
