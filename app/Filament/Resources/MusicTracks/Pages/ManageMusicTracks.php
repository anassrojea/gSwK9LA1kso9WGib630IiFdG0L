<?php

namespace App\Filament\Resources\MusicTracks\Pages;

use App\Filament\Resources\MusicTracks\MusicTrackResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMusicTracks extends ManageRecords
{
    protected static string $resource = MusicTrackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
