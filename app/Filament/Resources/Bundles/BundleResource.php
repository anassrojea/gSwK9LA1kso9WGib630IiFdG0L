<?php

namespace App\Filament\Resources\Bundles;

use App\Filament\AdminNavigation;
use App\Filament\Resources\Bundles\Pages\ManageBundles;
use App\Models\AuditLog;
use App\Models\Bundle;
use App\Models\Plan;
use App\Services\Payments\Currencies;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Family journey bundles (phase 8D, plan section 14.2): which event-plan
 * credits a bundle holds, its price per currency and how long its credits
 * last. Saving a bundle marks its prices as real (no longer placeholders),
 * so re-seeding leaves it alone.
 */
class BundleResource extends Resource
{
    protected static ?string $model = Bundle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Catalog;

    protected static ?int $navigationSort = 7;

    public static function getModelLabel(): string
    {
        return trans('journey.admin.bundles.label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('journey.admin.bundles.plural');
    }

    public static function form(Schema $schema): Schema
    {
        $plans = Plan::query()->whereNull('interval')->where('id', '!=', 'free')->orderBy('price_cents')->pluck('name', 'id')->all();

        return $schema->columns(1)->components([
            Section::make(trans('journey.admin.bundles.label'))
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextInput::make('id')->label(trans('journey.admin.bundles.id'))->required()->maxLength(64)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->unique(ignoreRecord: true)->disabledOn('edit')
                        ->extraInputAttributes(['dir' => 'ltr']),
                    TextInput::make('sort')->label(trans('journey.admin.bundles.sort'))->integer()->default(0),
                    TextInput::make('name_ar')->label(trans('journey.admin.bundles.name_ar'))->required()->maxLength(120),
                    TextInput::make('name_en')->label(trans('journey.admin.bundles.name_en'))->required()->maxLength(120),
                    Textarea::make('description_ar')->label(trans('journey.admin.bundles.description_ar'))->rows(2)->maxLength(500),
                    Textarea::make('description_en')->label(trans('journey.admin.bundles.description_en'))->rows(2)->maxLength(500),
                    TextInput::make('validity_days')->label(trans('journey.admin.bundles.validity_days'))->integer()->minValue(30)->maxValue(3650)->default(730)->required(),
                    Toggle::make('active')->label(trans('journey.admin.bundles.active'))->default(true),
                ]),
            Section::make(trans('journey.admin.bundles.credits'))->schema([
                Repeater::make('credits')->hiddenLabel()->minItems(1)->maxItems(6)->columns(2)->defaultItems(1)
                    ->schema([
                        Select::make('plan')->label(trans('journey.admin.bundles.plan'))->options($plans)->required(),
                        TextInput::make('count')->label(trans('journey.admin.bundles.count'))->integer()->minValue(1)->maxValue(10)->default(1)->required(),
                    ]),
            ]),
            Section::make(trans('journey.admin.bundles.prices'))
                ->description(trans('journey.admin.bundles.prices_help'))
                ->columns(['default' => 1, 'md' => count(Currencies::all())])
                ->schema(array_map(fn (string $currency) => TextInput::make("prices.{$currency}")
                    ->label($currency.' · '.trans("app.checkout.currencies.{$currency}"))
                    ->numeric()
                    ->minValue(0)
                    ->formatStateUsing(fn ($state) => $state === null || $state === '' ? null : Currencies::toMajor((int) $state, $currency))
                    ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : Currencies::toMinor($state, $currency)), Currencies::all())),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('id')->label(trans('journey.admin.bundles.id'))->searchable(),
                TextColumn::make('name')->label(trans('app.name_label'))->state(fn (Bundle $record) => $record->name()),
                TextColumn::make('credits')->label(trans('journey.admin.bundles.credits'))
                    ->state(fn (Bundle $record) => collect($record->creditCounts())->map(fn (int $n, string $plan) => $n.' × '.trans("app.wizard.tiers.{$plan}"))->implode(' + ')),
                TextColumn::make('prices')->label(trans('journey.admin.bundles.prices'))
                    ->state(fn (Bundle $record) => collect($record->prices ?? [])->filter(fn ($v) => $v !== null)->map(fn ($v, $c) => Currencies::format((int) $v, $c, app()->getLocale()))->implode(' · ')),
                IconColumn::make('placeholder_prices')->label(trans('journey.admin.bundles.placeholder'))->boolean(),
                TextColumn::make('issued_count')->label(trans('journey.admin.bundles.issued'))->counts('issued'),
                ToggleColumn::make('active')->label(trans('journey.admin.bundles.active')),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateDataUsing(fn (array $data) => $data + ['placeholder_prices' => false])
                    ->after(fn (Bundle $record) => AuditLog::record('bundle.updated', $record, $record->only(['credits', 'prices', 'validity_days', 'active']))),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBundles::route('/'),
        ];
    }
}
