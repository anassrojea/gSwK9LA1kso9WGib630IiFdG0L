<?php

namespace App\Filament\Resources\Events;

use App\Filament\Resources\Catalog\CatalogActions;
use App\Http\Controllers\Admin\PreviewController;
use App\Models\Entitlement;
use App\Models\Event;
use App\Services\Admin\EventStorage;
use App\Services\Entitlements;
use App\Services\Payments\OrderFulfillment;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * What an admin may do to a customer's event (plan section 4.3), each one
 * confirmed and audit-logged: extend storage, grant or revoke a feature
 * (through OrderFulfillment, the code path payments use). Nothing here
 * publishes or edits the invitation: that stays behind PublishGate.
 */
class EventActions
{
    public static function preview(): Action
    {
        return Action::make('preview_invitation')
            ->label(trans('admin.events.preview'))
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->color('gray')
            ->visible(fn (Event $record) => $record->invitation !== null)
            ->modalHeading(fn (Event $record) => trans('admin.catalog.preview_of', ['name' => $record->title]))
            ->modalWidth(Width::Large)
            ->modalContent(fn (Event $record) => CatalogActions::frame(PreviewController::eventUrl($record)))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(trans('admin.common.close'))
            ->extraModalFooterActions(fn (Event $record) => [
                Action::make('open_tab')
                    ->label(trans('admin.catalog.open_tab'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(PreviewController::eventUrl($record), shouldOpenInNewTab: true),
            ]);
    }

    public static function extendStorage(): Action
    {
        return Action::make('extend_storage')
            ->label(trans('admin.events.extend_storage'))
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedArchiveBox)
            ->modalHeading(fn (Event $record) => trans('admin.events.extend_heading', ['title' => $record->title]))
            ->modalDescription(fn (Event $record) => trans('admin.events.extend_text', [
                'date' => $record->storage_until?->translatedFormat('j F Y') ?? trans('admin.events.storage_none'),
            ]))
            ->schema([
                Radio::make('preset')
                    ->label(trans('admin.events.extend_by'))
                    ->options([
                        '90d' => trans('admin.events.presets.90d'),
                        '1y' => trans('admin.events.presets.1y'),
                        'custom' => trans('admin.events.presets.custom'),
                    ])
                    ->default('90d')
                    ->required()
                    ->live(),
                DatePicker::make('until')
                    ->label(trans('admin.events.storage_until'))
                    ->visible(fn (Get $get) => $get('preset') === 'custom')
                    ->required(fn (Get $get) => $get('preset') === 'custom')
                    ->after('today'),
                Textarea::make('note')->label(trans('app.admin.actions.note'))->rows(2)->maxLength(500),
            ])
            ->action(function (Event $record, array $data, Action $action) {
                $until = $data['preset'] === 'custom'
                    ? CarbonImmutable::parse($data['until'])
                    : EventStorage::presetDate($record, $data['preset']);

                static::run($action, fn () => app(EventStorage::class)->extend($record, $until, auth()->user(), $data['note'] ?? null));

                Notification::make()
                    ->title(trans('admin.events.extended', ['date' => $until->translatedFormat('j F Y')]))
                    ->success()
                    ->send();
            });
    }

    public static function grant(): Action
    {
        return Action::make('grant')
            ->label(trans('admin.entitlements.grant'))
            ->icon(Heroicon::OutlinedGift)
            ->color('gray')
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedGift)
            ->modalHeading(fn (Event $record) => trans('admin.entitlements.grant_heading', ['title' => $record->title]))
            ->modalDescription(trans('admin.entitlements.grant_text'))
            ->schema([
                Select::make('feature')
                    ->label(trans('admin.entitlements.feature'))
                    ->options(static::featureOptions())
                    ->searchable()
                    ->required(),
                DateTimePicker::make('until')
                    ->label(trans('admin.entitlements.until'))
                    ->helperText(trans('admin.entitlements.until_help'))
                    ->after('now'),
                Textarea::make('note')->label(trans('app.admin.actions.note'))->rows(2)->maxLength(500)->required(),
            ])
            ->action(function (Event $record, array $data, Action $action) {
                static::run($action, fn () => app(OrderFulfillment::class)->grantFeature(
                    $record,
                    $data['feature'],
                    filled($data['until'] ?? null) ? CarbonImmutable::parse($data['until']) : null,
                    auth()->user(),
                    $data['note'] ?? null,
                ));

                Notification::make()->title(trans('admin.entitlements.granted', ['feature' => static::featureLabel($data['feature'])]))->success()->send();
            });
    }

    public static function revoke(): Action
    {
        return Action::make('revoke')
            ->label(trans('admin.entitlements.revoke'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Entitlement $record) => $record->until === null || $record->until->isFuture())
            ->requiresConfirmation()
            ->modalHeading(fn (Entitlement $record) => trans('admin.entitlements.revoke_heading', ['feature' => static::featureLabel($record->feature)]))
            ->modalDescription(fn (Entitlement $record) => $record->source_order_id
                ? trans('admin.entitlements.revoke_paid')
                : trans('admin.entitlements.revoke_text'))
            ->schema([
                Textarea::make('reason')->label(trans('app.admin.actions.reason'))->rows(2)->maxLength(500)->required(),
            ])
            ->action(function (Entitlement $record, array $data, Action $action) {
                static::run($action, fn () => app(OrderFulfillment::class)->revokeEntitlement($record, auth()->user(), $data['reason']));

                Notification::make()->title(trans('admin.entitlements.revoked'))->success()->send();
            });
    }

    /**
     * Plan features (Entitlements::FEATURES) and what add-ons unlock (config/payments.php).
     *
     * @return array<string, string>
     */
    public static function featureOptions(): array
    {
        return collect(Entitlements::FEATURES)
            ->merge(collect(config('payments.addons'))->pluck('feature'))
            ->unique()
            ->mapWithKeys(fn (string $feature) => [$feature => static::featureLabel($feature)])
            ->sort()
            ->all();
    }

    public static function featureLabel(string $feature): string
    {
        $key = "admin.features.{$feature}";

        return trans()->has($key) ? trans($key) : $feature;
    }

    private static function run(Action $action, callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
            $action->halt();
        } catch (AuthorizationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();
        }
    }
}
