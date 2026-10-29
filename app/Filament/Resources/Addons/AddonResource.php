<?php

namespace App\Filament\Resources\Addons;

use App\Filament\AdminNavigation;
use App\Filament\Resources\Addons\Pages\ManageAddons;
use App\Models\Addon;
use App\Models\AuditLog;
use App\Services\Payments\Currencies;
use App\Services\Payments\PriceList;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Add-ons (plan section 13.2). What each one unlocks lives in
 * config/payments.php "addons"; prices per currency in Prices.
 */
class AddonResource extends Resource
{
    protected static ?string $model = Addon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Catalog;

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return trans('app.admin.addons.label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('app.admin.addons.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('id')
                ->label('ID')
                ->required()
                ->alphaDash()
                ->maxLength(40)
                ->unique(ignoreRecord: true)
                ->helperText(trans('app.admin.addons.id_help'))
                ->disabledOn('edit'),
            TextInput::make('name')->label(trans('app.name_label'))->required()->maxLength(120),
            TextInput::make('price_cents')
                ->label(trans('app.admin.addons.usd_price'))
                ->helperText(trans('app.admin.addons.usd_help'))
                ->numeric()
                ->minValue(0)
                ->required(),
            TextInput::make('currency')->default('USD')->disabled()->dehydrated(),
            Toggle::make('active')->label(trans('app.admin.addons.active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('price_cents')
            ->columns([
                TextColumn::make('id')->label('ID')->searchable(),
                TextColumn::make('name')->label(trans('app.name_label'))->searchable(),
                TextColumn::make('feature')->label(trans('app.admin.addons.feature'))
                    ->state(fn (Addon $record) => config("payments.addons.{$record->id}.feature") ?? '—'),
                TextColumn::make('prices')->label(trans('app.admin.prices.plural'))
                    ->state(fn (Addon $record) => collect(app(PriceList::class)->all($record))
                        ->filter(fn ($amount) => $amount !== null)
                        ->map(fn ($amount, $currency) => Currencies::format($amount, $currency, app()->getLocale()))
                        ->implode(' · ')),
                IconColumn::make('active')->label(trans('app.admin.addons.active'))->boolean(),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(fn (Addon $record) => AuditLog::record('addon.updated', $record, $record->only(['name', 'price_cents', 'active']))),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAddons::route('/'),
        ];
    }
}
