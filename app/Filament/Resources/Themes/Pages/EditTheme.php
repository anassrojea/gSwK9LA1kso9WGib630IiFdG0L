<?php

namespace App\Filament\Resources\Themes\Pages;

use App\Filament\Resources\Catalog\CatalogActions;
use App\Filament\Resources\Themes\ThemeResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTheme extends EditRecord
{
    use ValidatesTheme;

    protected static string $resource = ThemeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CatalogActions::preview(fn ($record) => ThemeResource::previewUrl($record)),
            CatalogActions::reset(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->validatedTheme($data, (string) $this->getRecord()->getKey(), $this->getRecord()->tokens ?? []);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return CatalogActions::saveEdited($record, $data, 'catalog.theme_updated');
    }
}
