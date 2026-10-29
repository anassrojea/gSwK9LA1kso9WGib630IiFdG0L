<?php

namespace App\Filament\Resources\Openings;

use App\Filament\AdminNavigation;
use App\Filament\Resources\Catalog\CatalogActions;
use App\Filament\Resources\Openings\Pages\ManageOpenings;
use App\Http\Controllers\Admin\PreviewController;
use App\Models\Opening;
use App\Models\Template;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Openings (plan section 6.3). Each one is code (resources/js/openings/{id}.js
 * and its Blade markup), so the admin edits names, tier and whether it is
 * offered, never adds one. The tier is what PlanAdvisor / PublishGate read.
 */
class OpeningResource extends Resource
{
    protected static ?string $model = Opening::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Catalog;

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return trans('admin.openings.label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('admin.openings.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name_ar')->label(trans('admin.catalog.name_ar'))->required()->maxLength(120)->extraInputAttributes(['dir' => 'rtl']),
            TextInput::make('name_en')->label(trans('admin.catalog.name_en'))->required()->maxLength(120)->extraInputAttributes(['dir' => 'ltr']),
            CatalogActions::tierSelect(),
            Toggle::make('active')->label(trans('admin.catalog.active'))->helperText(trans('admin.catalog.active_help')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('id')->label(trans('admin.catalog.id'))->fontFamily('mono')->sortable(),
                TextColumn::make(app()->getLocale() === 'ar' ? 'name_ar' : 'name_en')->label(trans('admin.catalog.name'))->searchable(['name_ar', 'name_en']),
                CatalogActions::tierColumn(),
                TextColumn::make('templates_count')->label(trans('admin.templates.plural'))->counts('templates'),
                TextColumn::make('module_path')->label(trans('admin.openings.module'))->fontFamily('mono')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('active')->label(trans('admin.catalog.active'))->boolean(),
                CatalogActions::editedColumn(),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (Opening $record, array $data) => CatalogActions::saveEdited($record, $data, 'catalog.opening_updated')),
                ActionGroup::make([
                    CatalogActions::preview(fn (Opening $record) => static::previewUrl($record)),
                    CatalogActions::reset(),
                ]),
            ]);
    }

    public static function previewUrl(Opening $opening): ?string
    {
        $template = Template::query()->where('opening_id', $opening->getKey())->orderBy('sort')->first()
            ?? Template::query()->orderBy('sort')->first();

        return $template ? PreviewController::templateUrl($template, ['opening' => $opening->getKey()]) : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageOpenings::route('/'),
        ];
    }
}
