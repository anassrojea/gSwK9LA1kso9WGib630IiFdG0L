<?php

namespace App\Filament\Resources\Openings\Pages;

use App\Filament\Resources\Catalog\CatalogActions;
use App\Filament\Resources\Openings\OpeningResource;
use Filament\Resources\Pages\ManageRecords;

class ManageOpenings extends ManageRecords
{
    protected static string $resource = OpeningResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CatalogActions::import(),
        ];
    }
}
