<?php

namespace App\Filament\Resources\Themes;

use App\Filament\AdminNavigation;
use App\Filament\Resources\Catalog\CatalogActions;
use App\Filament\Resources\Themes\Pages\CreateTheme;
use App\Filament\Resources\Themes\Pages\EditTheme;
use App\Filament\Resources\Themes\Pages\ListThemes;
use App\Http\Controllers\Admin\PreviewController;
use App\Models\Template;
use App\Models\Theme;
use App\Services\Catalog\CatalogValidator;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Themes (plan section 6): colour tokens, Google Fonts and radius, checked
 * against the theme block of the invitation config schema on save. The tier
 * is what PlanAdvisor / PublishGate read when an invitation uses the theme.
 */
class ThemeResource extends Resource
{
    protected static ?string $model = Theme::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Catalog;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name_en';

    /** Arabic and Latin Google Fonts that suit invitations, offered as suggestions. */
    public const FONT_SUGGESTIONS = [
        'Amiri', 'Aref Ruqaa', 'Katibeh', 'Mirza', 'Lateef', 'Reem Kufi', 'Noto Naskh Arabic', 'Noto Kufi Arabic',
        'Cairo', 'Almarai', 'Tajawal', 'El Messiri', 'Harmattan', 'Scheherazade New', 'Marhey', 'Rakkas',
        'Playfair Display', 'Cormorant Garamond', 'Great Vibes', 'Inter',
    ];

    public static function getModelLabel(): string
    {
        return trans('admin.themes.label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('admin.themes.plural');
    }

    public static function form(Schema $schema): Schema
    {
        $color = fn (string $key, bool $required) => ColorPicker::make("tokens.colors.{$key}")
            ->label(trans("admin.themes.colors.{$key}"))
            ->hex()
            ->extraInputAttributes(['dir' => 'ltr'])
            ->regex('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/')
            ->validationMessages(['regex' => trans('admin.themes.errors.color')])
            ->required($required);

        return $schema->columns(1)->components([
            Section::make(trans('admin.themes.label'))
                ->columns(['default' => 1, 'md' => 2])
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
                    CatalogActions::tierSelect()->default('free'),
                    TextInput::make('name_ar')->label(trans('admin.catalog.name_ar'))->required()->maxLength(120)->extraInputAttributes(['dir' => 'rtl']),
                    TextInput::make('name_en')->label(trans('admin.catalog.name_en'))->required()->maxLength(120)->extraInputAttributes(['dir' => 'ltr']),
                    Toggle::make('active')->label(trans('admin.catalog.active'))->helperText(trans('admin.catalog.active_help'))->default(true),
                ]),
            Section::make(trans('admin.themes.colors_title'))
                ->description(trans('admin.themes.colors_help'))
                ->columns(['default' => 2, 'md' => 5])
                ->schema([
                    ...array_map(fn ($key) => $color($key, true), CatalogValidator::REQUIRED_COLORS),
                    ...array_map(fn ($key) => $color($key, false), CatalogValidator::OPTIONAL_COLORS),
                ]),
            Section::make(trans('admin.themes.fonts_title'))
                ->description(trans('admin.themes.fonts_help'))
                ->columns(['default' => 1, 'md' => 4])
                ->schema([
                    ...array_map(fn ($role) => TextInput::make("fonts.{$role}")
                        ->label(trans("admin.themes.fonts.{$role}"))
                        ->required()
                        ->regex('/^[A-Za-z0-9 ]{2,40}$/')
                        ->validationMessages(['regex' => trans('admin.themes.errors.font')])
                        ->datalist(self::FONT_SUGGESTIONS)
                        ->extraInputAttributes(['dir' => 'ltr']), CatalogValidator::FONT_ROLES),
                    TextInput::make('tokens.radius')
                        ->label(trans('admin.themes.radius'))
                        ->helperText(trans('admin.themes.radius_help'))
                        ->integer()
                        ->minValue(0)
                        ->maxValue(32)
                        ->suffix('px'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                ColorColumn::make('swatch')
                    ->label(trans('admin.themes.colors_title'))
                    ->state(fn (Theme $record) => array_values(array_intersect_key($record->tokens['colors'] ?? [], array_flip(CatalogValidator::REQUIRED_COLORS)))),
                TextColumn::make('id')->label(trans('admin.catalog.id'))->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make(app()->getLocale() === 'ar' ? 'name_ar' : 'name_en')->label(trans('admin.catalog.name'))->searchable(['name_ar', 'name_en']),
                TextColumn::make('fonts.display')->label(trans('admin.themes.fonts.display'))->toggleable(),
                CatalogActions::tierColumn(),
                TextColumn::make('templates_count')->label(trans('admin.templates.plural'))->counts('templates')->sortable(),
                IconColumn::make('active')->label(trans('admin.catalog.active'))->boolean(),
                CatalogActions::editedColumn(),
            ])
            ->filters([
                SelectFilter::make('tier')->label(trans('admin.catalog.tier'))->options(CatalogActions::tierOptions()),
                TernaryFilter::make('active')->label(trans('admin.catalog.active')),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    CatalogActions::preview(fn (Theme $record) => static::previewUrl($record)),
                    CatalogActions::reset(),
                ]),
            ]);
    }

    /**
     * The theme on a template that uses it (or the first template).
     */
    public static function previewUrl(Theme $theme): ?string
    {
        $template = Template::query()->where('theme_id', $theme->getKey())->orderBy('sort')->first()
            ?? Template::query()->orderBy('sort')->first();

        return $template ? PreviewController::templateUrl($template, ['theme' => $theme->getKey()]) : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListThemes::route('/'),
            'create' => CreateTheme::route('/create'),
            'edit' => EditTheme::route('/{record}/edit'),
        ];
    }
}
