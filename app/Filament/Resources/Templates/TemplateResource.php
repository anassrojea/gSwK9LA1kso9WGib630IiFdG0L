<?php

namespace App\Filament\Resources\Templates;

use App\Filament\AdminNavigation;
use App\Filament\Resources\Catalog\CatalogActions;
use App\Filament\Resources\Templates\Pages\CreateTemplate;
use App\Filament\Resources\Templates\Pages\EditTemplate;
use App\Filament\Resources\Templates\Pages\ListTemplates;
use App\Http\Controllers\Admin\PreviewController;
use App\Models\Event;
use App\Models\Opening;
use App\Models\Template;
use App\Models\Theme;
use App\Services\Catalog\CatalogValidator;
use BackedEnum;
use Closure;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use JsonException;
use UnitEnum;

/**
 * Templates (plan section 6): an opening + a theme + default sections for an
 * occasion. default_config is edited as JSON and checked with the invitation
 * config schema on save (InvitationConfigValidator, via CatalogValidator).
 */
class TemplateResource extends Resource
{
    protected static ?string $model = Template::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Catalog;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name_en';

    public static function getModelLabel(): string
    {
        return trans('admin.templates.label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('admin.templates.plural');
    }

    public static function form(Schema $schema): Schema
    {
        $name = app()->getLocale() === 'ar' ? 'name_ar' : 'name_en';

        return $schema->columns(1)->components([
            Section::make(trans('admin.templates.label'))
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextInput::make('id')
                        ->label(trans('admin.catalog.id'))
                        ->helperText(trans('admin.catalog.id_help'))
                        ->required()
                        ->maxLength(64)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->validationMessages(['regex' => trans('admin.catalog.errors.slug')])
                        ->unique(ignoreRecord: true)
                        ->disabledOn('edit'),
                    Select::make('occasion')
                        ->label(trans('admin.templates.occasion'))
                        ->options(static::occasionOptions())
                        ->required(),
                    CatalogActions::tierSelect()->default('premium'),
                    TextInput::make('name_ar')->label(trans('admin.catalog.name_ar'))->required()->maxLength(120)->extraInputAttributes(['dir' => 'rtl']),
                    TextInput::make('name_en')->label(trans('admin.catalog.name_en'))->required()->maxLength(120)->extraInputAttributes(['dir' => 'ltr']),
                    TextInput::make('sort')->label(trans('admin.templates.sort'))->integer()->default(100)->required(),
                    Select::make('opening_id')
                        ->label(trans('admin.openings.label'))
                        ->options(fn () => Opening::query()->orderBy('id')->get()->mapWithKeys(fn (Opening $o) => [$o->id => $o->{$name}.' · '.trans("app.wizard.tiers.{$o->tier}")])->all())
                        ->required(),
                    Select::make('theme_id')
                        ->label(trans('admin.themes.label'))
                        ->options(fn () => Theme::query()->orderBy('id')->get()->mapWithKeys(fn (Theme $t) => [$t->id => $t->{$name}.' · '.trans("app.wizard.tiers.{$t->tier}")])->all())
                        ->searchable()
                        ->required(),
                    Toggle::make('active')->label(trans('admin.catalog.active'))->helperText(trans('admin.catalog.active_help'))->default(true),
                ]),
            Section::make(trans('admin.templates.config'))
                ->description(trans('admin.templates.config_help'))
                ->schema([
                    CodeEditor::make('default_config')
                        ->hiddenLabel()
                        ->language(Language::Json)
                        // JSON reads left to right even in the Arabic admin
                        ->extraAttributes(['dir' => 'ltr', 'style' => 'max-height: 36rem; overflow: auto'])
                        ->required()
                        ->formatStateUsing(fn ($state) => is_array($state)
                            ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                            : $state)
                        ->dehydrateStateUsing(fn (?string $state) => static::decode($state))
                        ->showAllValidationMessages()
                        ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                            foreach (static::configProblems($value, $get('occasion'), $get('opening_id'), $get('theme_id')) as $problem) {
                                $fail($problem);
                            }
                        }),
                ]),
        ]);
    }

    /**
     * Readable problems with a typed default_config (at most 10), empty when it can be saved.
     *
     * @return list<string>
     */
    public static function configProblems(mixed $json, ?string $occasion, ?string $openingId, ?string $themeId): array
    {
        try {
            $config = is_array($json) ? $json : json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [trans('admin.templates.errors.json', ['error' => $e->getMessage()])];
        }

        $problems = app(CatalogValidator::class)->templateErrors($config, $occasion, $openingId, $themeId);
        if (count($problems) > 10) {
            $problems = [...array_slice($problems, 0, 10), trans('admin.templates.errors.more', ['count' => count($problems) - 10])];
        }

        return $problems;
    }

    public static function decode(?string $json): ?array
    {
        try {
            return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    public static function occasionOptions(): array
    {
        return collect(Event::OCCASIONS)->mapWithKeys(fn (string $o) => [$o => trans("app.wizard.occasions.{$o}")])->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('sort')->label('#')->sortable()->toggleable(),
                TextColumn::make(app()->getLocale() === 'ar' ? 'name_ar' : 'name_en')
                    ->label(trans('admin.catalog.name'))
                    ->description(fn (Template $record) => $record->id)
                    ->searchable(['name_ar', 'name_en', 'id']),
                TextColumn::make('occasion')->label(trans('admin.templates.occasion'))
                    ->formatStateUsing(fn (string $state) => static::occasionOptions()[$state] ?? $state)
                    ->badge()->color('gray'),
                TextColumn::make('opening_id')->label(trans('admin.openings.label'))->fontFamily('mono'),
                TextColumn::make('theme_id')->label(trans('admin.themes.label'))->fontFamily('mono')->toggleable(),
                CatalogActions::tierColumn(),
                IconColumn::make('active')->label(trans('admin.catalog.active'))->boolean(),
                CatalogActions::editedColumn(),
            ])
            ->filters([
                SelectFilter::make('occasion')->label(trans('admin.templates.occasion'))->options(static::occasionOptions()),
                SelectFilter::make('tier')->label(trans('admin.catalog.tier'))->options(CatalogActions::tierOptions()),
                TernaryFilter::make('active')->label(trans('admin.catalog.active')),
            ])
            ->recordActions([
                CatalogActions::preview(fn (Template $record) => PreviewController::templateUrl($record)),
                ActionGroup::make([
                    EditAction::make(),
                    CatalogActions::reset(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTemplates::route('/'),
            'create' => CreateTemplate::route('/create'),
            'edit' => EditTemplate::route('/{record}/edit'),
        ];
    }
}
