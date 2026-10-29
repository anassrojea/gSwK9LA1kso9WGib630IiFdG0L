<?php

namespace App\Filament\Resources\Addons\Pages;

use App\Filament\Resources\Addons\AddonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAddons extends ManageRecords
{
    protected static string $resource = AddonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
