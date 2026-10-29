<?php

namespace App\Filament\Resources\Prices;

use App\Filament\Resources\Prices\Pages\ManagePrices;
use App\Models\Addon;
use App\Models\Plan;
use App\Models\Price;
use App\Services\Payments\Currencies;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/**
 * Price lists per currency (plan section 4.3: "prices per currency"). Amounts
 * are typed in normal units (109 riyals, 350000 pounds) and stored in minor
 * units (App\Services\Payments\Currencies). Seeded SAR/SYP prices are marked
 * "placeholder" until an admin saves them.
 */
class PriceResource extends Resource
{
    protected static ?string $model = Price::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return trans('app.admin.prices.label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('app.admin.prices.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('priceable_type')
                ->label(trans('app.admin.orders.type'))
                ->options(['plan' => trans('app.checkout.plan'), 'addon' => trans('app.checkout.addons_title')])
                ->required()
                ->live(),
            Select::make('priceable_id')
                ->label(trans('app.admin.prices.item'))
                ->options(fn (Get $get) => $get('priceable_type') === 'addon'
                    ? Addon::query()->orderBy('id')->pluck('name', 'id')->all()
                    : Plan::query()->orderBy('price_cents')->pluck('name', 'id')->all())
                ->required()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule
                    ->where('priceable_type', $get('priceable_type'))
                    ->where('currency', $get('currency'))),
            Select::make('currency')
                ->label(trans('app.checkout.currency'))
                ->options(collect(Currencies::all())->mapWithKeys(fn ($c) => [$c => $c.' · '.trans("app.checkout.currencies.{$c}")])->all())
                ->required()
                ->live(),
            TextInput::make('amount')
                ->label(trans('app.payments.amount'))
                ->helperText(fn (Get $get) => trans('app.admin.prices.amount_help', ['digits' => Currencies::exponent($get('currency') ?? 'USD')]))
                ->numeric()
                ->minValue(0)
                ->required()
                ->formatStateUsing(fn ($state, ?Price $record) => $record ? Currencies::toMajor((int) $state, $record->currency) : $state)
                ->dehydrateStateUsing(fn ($state, Get $get) => Currencies::toMinor($state, $get('currency') ?? 'USD')),
            // saving a price in the admin makes it real
            Hidden::make('placeholder')->default(false)->dehydrateStateUsing(fn () => false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('priceable_id')
            ->columns([
                TextColumn::make('priceable_type')->label(trans('app.admin.orders.type'))->badge(),
                TextColumn::make('priceable_id')->label(trans('app.admin.prices.item'))->searchable()->sortable(),
                TextColumn::make('currency')->label(trans('app.checkout.currency'))->sortable(),
                TextColumn::make('amount')->label(trans('app.payments.amount'))
                    ->formatStateUsing(fn (Price $record) => Currencies::format($record->amount, $record->currency, app()->getLocale()))
                    ->sortable(),
                TextColumn::make('placeholder')->label(trans('app.admin.prices.state'))
                    ->formatStateUsing(fn (bool $state) => $state ? trans('app.admin.prices.placeholder') : trans('app.admin.prices.set'))
                    ->badge()
                    ->color(fn (bool $state) => $state ? 'warning' : 'success'),
                TextColumn::make('updated_at')->label(trans('app.admin.prices.updated'))->dateTime()->since()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('currency')->label(trans('app.checkout.currency'))->options(array_combine(Currencies::all(), Currencies::all())),
                SelectFilter::make('priceable_type')->label(trans('app.admin.orders.type'))->options(['plan' => trans('app.checkout.plan'), 'addon' => trans('app.checkout.addons_title')]),
                TernaryFilter::make('placeholder')->label(trans('app.admin.prices.placeholder')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePrices::route('/'),
        ];
    }
}
