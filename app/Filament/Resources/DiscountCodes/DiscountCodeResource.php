<?php

namespace App\Filament\Resources\DiscountCodes;

use App\Filament\AdminNavigation;
use App\Filament\Resources\DiscountCodes\Pages\ManageDiscountCodes;
use App\Models\Addon;
use App\Models\AuditLog;
use App\Models\DiscountCode;
use App\Models\Plan;
use App\Services\Digits;
use App\Services\Payments\Currencies;
use App\Services\Payments\Discounts;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Discount codes (plan section 4.3); the rules are in App\Services\Payments\Discounts.
 * A code that was used (any order carries it) can be switched off but not deleted.
 */
class DiscountCodeResource extends Resource
{
    protected static ?string $model = DiscountCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Sales;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getModelLabel(): string
    {
        return trans('admin.discounts.label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('admin.discounts.plural');
    }

    public static function form(Schema $schema): Schema
    {
        $isFixed = fn (Get $get) => $get('kind') === 'fixed';

        return $schema->columns(1)->components([
            Section::make(trans('admin.discounts.label'))
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextInput::make('code')
                        ->label(trans('admin.discounts.code'))
                        ->helperText(trans('admin.discounts.code_help'))
                        ->required()
                        ->maxLength(40)
                        ->regex('/^[A-Za-z0-9][A-Za-z0-9_-]{2,39}$/')
                        ->validationMessages(['regex' => trans('admin.discounts.errors.code')])
                        ->dehydrateStateUsing(fn (?string $state) => DiscountCode::normalize($state))
                        // unique ignoring case (the database index is on lower(code))
                        ->rule(fn (?DiscountCode $record): Closure => function (string $attribute, $value, Closure $fail) use ($record) {
                            $taken = DiscountCode::query()
                                ->whereRaw('lower(code) = lower(?)', [DiscountCode::normalize($value)])
                                ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                                ->exists();
                            if ($taken) {
                                $fail(trans('validation.unique', ['attribute' => trans('admin.discounts.code')]));
                            }
                        })
                        ->extraInputAttributes(['dir' => 'ltr', 'style' => 'text-transform:uppercase']),
                    TextInput::make('description')->label(trans('admin.discounts.description'))->maxLength(255),
                    Radio::make('kind')
                        ->label(trans('admin.discounts.kind'))
                        ->options(['percent' => trans('admin.discounts.kinds.percent'), 'fixed' => trans('admin.discounts.kinds.fixed')])
                        ->default('percent')
                        ->inline()
                        ->live()
                        ->afterStateHydrated(fn (Radio $component, ?DiscountCode $record) => $component->state($record && ! $record->isPercent() ? 'fixed' : 'percent'))
                        ->columnSpanFull(),
                    TextInput::make('percent')
                        ->label(trans('admin.discounts.percent'))
                        ->helperText(trans('admin.discounts.percent_help'))
                        ->integer()
                        ->minValue(1)
                        ->maxValue(100)
                        ->suffix('%')
                        ->visible(fn (Get $get) => ! $isFixed($get))
                        ->required(fn (Get $get) => ! $isFixed($get)),
                ]),
            Section::make(trans('admin.discounts.amounts'))
                ->description(trans('admin.discounts.amounts_help'))
                ->visible($isFixed)
                ->columns(['default' => 1, 'md' => count(Currencies::all())])
                ->schema(array_map(fn (string $currency) => TextInput::make("amounts.{$currency}")
                    ->label($currency.' · '.trans("app.checkout.currencies.{$currency}"))
                    ->numeric()
                    ->minValue(0)
                    ->formatStateUsing(fn ($state) => $state === null ? null : Currencies::toMajor((int) $state, $currency))
                    ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : Currencies::toMinor($state, $currency))
                    // at least one currency
                    ->required(fn (Get $get) => $isFixed($get) && collect(Currencies::all())->every(fn ($c) => blank($get("amounts.{$c}"))))
                    ->markAsRequired(false)
                    ->validationMessages(['required' => trans('admin.discounts.errors.amounts')]), Currencies::all())),
            Section::make(trans('admin.discounts.applies'))
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Toggle::make('applies_to_plans')->label(trans('admin.discounts.to_plans'))->default(true)->live()
                        ->accepted(fn (Get $get) => ! $get('applies_to_addons'))
                        ->validationMessages(['accepted' => trans('admin.discounts.errors.targets')]),
                    Toggle::make('applies_to_addons')->label(trans('admin.discounts.to_addons'))->default(true)->live(),
                    Select::make('plan_ids')
                        ->label(trans('admin.discounts.only_plans'))
                        ->helperText(trans('admin.discounts.only_help'))
                        ->multiple()
                        ->options(fn () => Plan::query()->where('id', '!=', 'free')->orderBy('price_cents')->pluck('name', 'id')->all())
                        ->visible(fn (Get $get) => (bool) $get('applies_to_plans')),
                    Select::make('addon_ids')
                        ->label(trans('admin.discounts.only_addons'))
                        ->helperText(trans('admin.discounts.only_help'))
                        ->multiple()
                        ->options(fn () => Addon::query()->orderBy('price_cents')->pluck('name', 'id')->all())
                        ->visible(fn (Get $get) => (bool) $get('applies_to_addons')),
                ]),
            Section::make(trans('admin.discounts.limits'))
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    DateTimePicker::make('starts_at')->label(trans('admin.discounts.starts_at')),
                    DateTimePicker::make('ends_at')->label(trans('admin.discounts.ends_at'))->after('starts_at'),
                    TextInput::make('max_uses')->label(trans('admin.discounts.max_uses'))->helperText(trans('admin.discounts.max_uses_help'))->integer()->minValue(1),
                    TextInput::make('max_uses_per_user')->label(trans('admin.discounts.max_uses_per_user'))->integer()->minValue(1),
                    Toggle::make('active')->label(trans('admin.discounts.active'))->default(true),
                ]),
        ]);
    }

    /**
     * Exactly one of percent / amounts is stored; empty lists mean "all".
     */
    public static function normalizeData(array $data): array
    {
        $kind = $data['kind'] ?? ($data['percent'] ?? null ? 'percent' : 'fixed');
        unset($data['kind']);

        if ($kind === 'fixed') {
            $data['percent'] = null;
            $data['amounts'] = array_filter($data['amounts'] ?? [], fn ($v) => $v !== null);
        } else {
            $data['amounts'] = null;
        }
        foreach (['plan_ids' => 'applies_to_plans', 'addon_ids' => 'applies_to_addons'] as $list => $flag) {
            $data[$list] = ! empty($data[$flag]) && ! empty($data[$list]) ? array_values($data[$list]) : null;
        }

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->withUses()->withCount('orders'))
            ->columns([
                TextColumn::make('code')->label(trans('admin.discounts.code'))->searchable()->copyable()->weight('bold')->fontFamily('mono')
                    ->description(fn (DiscountCode $record) => $record->description),
                TextColumn::make('value')->label(trans('admin.discounts.value'))
                    ->state(fn (DiscountCode $record) => Discounts::label($record, app()->getLocale()))
                    ->badge()->color('success'),
                TextColumn::make('applies')->label(trans('admin.discounts.applies'))
                    ->state(fn (DiscountCode $record) => array_values(array_filter([
                        $record->applies_to_plans ? ($record->plan_ids ? implode(', ', $record->plan_ids) : trans('admin.discounts.all_plans')) : null,
                        $record->applies_to_addons ? ($record->addon_ids ? implode(', ', $record->addon_ids) : trans('admin.discounts.all_addons')) : null,
                    ])))
                    ->listWithLineBreaks()
                    ->toggleable(),
                TextColumn::make('uses_count')->label(trans('admin.discounts.uses'))
                    ->formatStateUsing(fn (DiscountCode $record) => Digits::localize((int) $record->uses_count, app()->getLocale())
                        .($record->max_uses ? ' / '.Digits::localize($record->max_uses, app()->getLocale()) : ''))
                    ->sortable(),
                TextColumn::make('ends_at')->label(trans('admin.discounts.ends_at'))->dateTime()->placeholder(trans('admin.discounts.no_end'))->sortable(),
                ToggleColumn::make('active')->label(trans('admin.discounts.active'))
                    ->afterStateUpdated(fn (DiscountCode $record, bool $state) => AuditLog::record('discount.'.($state ? 'activated' : 'deactivated'), $record, ['code' => $record->code])),
            ])
            ->filters([
                TernaryFilter::make('active')->label(trans('admin.discounts.active')),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(function (DiscountCode $record, array $data) {
                        $record->update(static::normalizeData($data));
                        AuditLog::record('discount.updated', $record, ['code' => $record->code, 'changed' => array_keys(array_diff_key($record->getChanges(), ['updated_at' => true]))]);

                        return $record;
                    }),
                DeleteAction::make()
                    ->visible(fn (DiscountCode $record) => (int) $record->orders_count === 0)
                    ->before(fn (DiscountCode $record) => AuditLog::record('discount.deleted', $record, ['code' => $record->code])),
            ]);
    }

    public static function canDelete(Model $record): bool
    {
        return ! $record->orders()->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDiscountCodes::route('/'),
        ];
    }
}
