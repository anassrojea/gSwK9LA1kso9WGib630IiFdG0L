<?php

namespace App\Filament\Resources\VenueListings\Pages;

use App\Filament\Resources\VenueListings\VenueListingResource;
use Filament\Resources\Pages\ListRecords;

class ListVenueListings extends ListRecords
{
    protected static string $resource = VenueListingResource::class;
}
