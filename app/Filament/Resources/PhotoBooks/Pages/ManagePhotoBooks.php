<?php

namespace App\Filament\Resources\PhotoBooks\Pages;

use App\Filament\Resources\PhotoBooks\PhotoBookResource;
use Filament\Resources\Pages\ManageRecords;

class ManagePhotoBooks extends ManageRecords
{
    protected static string $resource = PhotoBookResource::class;
}
