<?php

namespace App\Filament\Resources\Templates\Pages;

use App\Filament\Resources\Catalog\CatalogActions;
use App\Filament\Resources\Templates\TemplateResource;
use App\Http\Controllers\Admin\PreviewController;
use App\Models\Template;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTemplate extends EditRecord
{
    protected static string $resource = TemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CatalogActions::preview(fn (Template $record) => PreviewController::templateUrl($record)),
            CatalogActions::reset(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return CatalogActions::saveEdited($record, $data, 'catalog.template_updated');
    }
}
