<?php

namespace App\Filament\Resources\Marketplace\Pages;

use App\Filament\Resources\Marketplace\VendorListingResource;
use Filament\Resources\Pages\ListRecords;

class ListVendorListings extends ListRecords
{
    protected static string $resource = VendorListingResource::class;
}
