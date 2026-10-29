<?php

namespace App\Filament\Resources\Catalog;

use App\Models\AuditLog;
use App\Models\Opening;
use App\Models\Template;
use App\Models\Theme;
use App\Services\Catalog\CatalogSync;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * What the three catalog resources (openings, themes, templates) share: the
 * tier, the preview, "Import from templates/" and "Reset to templates/", and
 * the admin_edited_at flag that keeps CatalogSeeder from overwriting edits.
 */
class CatalogActions
{
    public const TIERS = ['free', 'premium', 'signature'];

    public const TIER_COLORS = ['free' => 'gray', 'premium' => 'info', 'signature' => 'warning'];

    /**
     * @return array<string, string>
     */
    public static function tierOptions(): array
    {
        return collect(self::TIERS)->mapWithKeys(fn (string $tier) => [$tier => trans("app.wizard.tiers.{$tier}")])->all();
    }

    public static function tierSelect(): Select
    {
        return Select::make('tier')
            ->label(trans('admin.catalog.tier'))
            ->options(static::tierOptions())
            ->helperText(trans('admin.catalog.tier_help'))
            ->required()
            ->selectablePlaceholder(false);
    }

    public static function tierColumn(): TextColumn
    {
        return TextColumn::make('tier')
            ->label(trans('admin.catalog.tier'))
            ->formatStateUsing(fn (string $state) => trans("app.wizard.tiers.{$state}"))
            ->badge()
            ->color(fn (string $state) => self::TIER_COLORS[$state] ?? 'gray')
            ->sortable();
    }

    public static function editedColumn(): TextColumn
    {
        return TextColumn::make('admin_edited_at')
            ->label(trans('admin.catalog.source'))
            ->state(fn (Model $record) => $record->admin_edited_at ? 'admin' : 'file')
            ->formatStateUsing(fn (string $state) => trans("admin.catalog.sources.{$state}"))
            ->tooltip(fn (Model $record) => $record->admin_edited_at ? trans('admin.catalog.edited_tooltip', ['at' => $record->admin_edited_at->diffForHumans()]) : null)
            ->badge()
            ->color(fn (string $state) => $state === 'admin' ? 'warning' : 'gray')
            ->toggleable();
    }

    /**
     * A phone-sized iframe of a signed admin preview (App\Http\Controllers\Admin\PreviewController).
     *
     * @param  Closure(Model): ?string  $url
     */
    public static function preview(Closure $url, string $name = 'preview'): Action
    {
        return Action::make($name)
            ->label(trans('admin.catalog.preview'))
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->modalHeading(fn (Model $record) => trans('admin.catalog.preview_of', ['name' => static::name($record)]))
            ->modalWidth(Width::Large)
            ->modalContent(fn (Model $record) => static::frame($url($record)))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(trans('admin.common.close'))
            ->extraModalFooterActions(fn (Model $record) => [
                Action::make('open_tab')
                    ->label(trans('admin.catalog.open_tab'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url($url($record), shouldOpenInNewTab: true),
            ]);
    }

    public static function frame(?string $url): HtmlString
    {
        if (! $url) {
            return new HtmlString('<p class="text-sm text-gray-500">'.e(trans('admin.catalog.no_preview')).'</p>');
        }

        return new HtmlString('<div style="display:flex;justify-content:center"><iframe src="'.e($url).'" data-admin-preview title="'.e(trans('admin.catalog.preview')).'" '
            .'style="width:100%;max-width:390px;height:70vh;border:1px solid rgba(0,0,0,.12);border-radius:16px;background:#fff" loading="lazy"></iframe></div>');
    }

    /**
     * Header action: insert and update the catalog from templates/ (CatalogSync rules).
     */
    public static function import(): Action
    {
        return Action::make('import')
            ->label(trans('admin.catalog.import'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(trans('admin.catalog.import'))
            ->modalDescription(trans('admin.catalog.import_text'))
            ->schema([
                Toggle::make('overwrite')
                    ->label(trans('admin.catalog.overwrite'))
                    ->helperText(trans('admin.catalog.overwrite_help'))
                    ->default(false),
            ])
            ->action(function (array $data, Action $action) {
                try {
                    $report = app(CatalogSync::class)->sync(force: (bool) ($data['overwrite'] ?? false));
                } catch (ValidationException $e) {
                    Notification::make()->title(trans('admin.catalog.import_failed'))->body(collect($e->errors())->flatten()->first())->danger()->send();
                    $action->halt();

                    return;
                }

                AuditLog::record('catalog.imported', auth()->user(), [
                    'overwrite' => (bool) ($data['overwrite'] ?? false),
                    'inserted' => $report['inserted'],
                    'updated' => $report['updated'],
                    'skipped' => $report['skipped'],
                ]);

                Notification::make()
                    ->title(trans('admin.catalog.imported'))
                    ->body(trans('admin.catalog.import_report', [
                        'inserted' => count($report['inserted']),
                        'updated' => count($report['updated']),
                        'skipped' => count($report['skipped']),
                    ]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Row action: drop the admin's edits and take the file's version again.
     */
    public static function reset(): Action
    {
        return Action::make('reset')
            ->label(trans('admin.catalog.reset'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->visible(fn (Model $record) => $record->admin_edited_at !== null && app(CatalogSync::class)->hasSource($record))
            ->requiresConfirmation()
            ->modalHeading(fn (Model $record) => trans('admin.catalog.reset_heading', ['name' => static::name($record)]))
            ->modalDescription(trans('admin.catalog.reset_text'))
            ->action(function (Model $record) {
                $before = $record->only(['tier', 'active']);
                app(CatalogSync::class)->reset($record);
                AuditLog::record('catalog.reset', $record, ['before' => $before, 'after' => $record->fresh()->only(['tier', 'active'])]);

                Notification::make()->title(trans('admin.catalog.reset_done'))->success()->send();
            });
    }

    /**
     * Save an admin edit: flag the row so re-seeding keeps it, and audit-log what changed.
     */
    public static function saveEdited(Model $record, array $data, string $action): Model
    {
        $record->fill($data);
        $changed = array_keys($record->getDirty());
        $tier = $record->isDirty('tier') ? ['from' => $record->getOriginal('tier'), 'to' => $record->tier] : null;

        if ($changed !== []) {
            $record->forceFill(['admin_edited_at' => now()]);
        }
        $record->save();

        if ($changed !== []) {
            AuditLog::record($action, $record, array_filter(['changed' => $changed, 'tier' => $tier]));
        }

        return $record;
    }

    public static function createEdited(string $model, array $data, string $action): Model
    {
        /** @var Model $record */
        $record = new $model;
        $record->forceFill($data + ['admin_edited_at' => now()])->save();
        AuditLog::record($action, $record, ['tier' => $record->tier]);

        return $record;
    }

    public static function name(Model $record): string
    {
        if ($record instanceof Opening || $record instanceof Theme || $record instanceof Template) {
            return app()->getLocale() === 'ar' ? $record->name_ar : $record->name_en;
        }

        return (string) $record->getKey();
    }
}
