<?php

namespace App\Filament\Resources\Themes\Pages;

use App\Filament\Resources\Catalog\CatalogActions;
use App\Filament\Resources\Themes\ThemeResource;
use App\Models\Theme;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTheme extends CreateRecord
{
    use ValidatesTheme;

    protected static string $resource = ThemeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->validatedTheme($data, (string) ($data['id'] ?? ''));
    }

    protected function handleRecordCreation(array $data): Model
    {
        return CatalogActions::createEdited(Theme::class, $data, 'catalog.theme_created');
    }
}
