<?php

namespace App\Filament\Resources\Organizations;

use App\Models\Organization;
use App\Models\OrgSubscription;
use App\Services\Org\OrgSubscriptions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * The admin's subscription actions on an organization (phase 8A), each
 * confirmed and audit-logged through OrgSubscriptions: extend a transfer
 * subscription's period by some days (also reviving an overdue one), or
 * cancel the live subscription now (at the provider too).
 */
class OrganizationActions
{
    public static function extend(): Action
    {
        return Action::make('extend')
            ->label(trans('org.admin.extend'))
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->visible(fn (Organization $record) => static::target($record)?->isTransfer() ?? false)
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedCalendarDays)
            ->schema([
                TextInput::make('days')->label(trans('org.admin.extend_days'))->integer()->minValue(1)->maxValue(366)->default(7)->required(),
                Textarea::make('note')->label(trans('org.admin.extend_note'))->rows(2)->maxLength(500)->required(),
            ])
            ->action(function (Organization $record, array $data, Action $action) {
                $subscription = static::target($record);
                $updated = null;
                static::run($action, function () use ($subscription, $data, &$updated) {
                    $updated = app(OrgSubscriptions::class)->adminExtend($subscription, (int) $data['days'], auth()->user(), $data['note'] ?? null);
                });

                Notification::make()->title(trans('org.admin.extended', ['date' => $updated?->current_period_end?->translatedFormat('j F Y')]))->success()->send();
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel_subscription')
            ->label(trans('org.admin.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Organization $record) => $record->subscription()->exists())
            ->requiresConfirmation()
            ->modalDescription(trans('org.admin.cancel_text'))
            ->schema([
                Textarea::make('reason')->label(trans('org.admin.cancel_reason'))->rows(2)->maxLength(500)->required(),
            ])
            ->action(function (Organization $record, array $data, Action $action) {
                $subscription = $record->subscription()->firstOrFail();
                static::run($action, fn () => app(OrgSubscriptions::class)->adminCancel($subscription, auth()->user(), $data['reason']));

                Notification::make()->title(trans('org.admin.canceled'))->success()->send();
            });
    }

    /**
     * The live subscription, else the latest one (an expired transfer subscription can be revived).
     */
    public static function target(Organization $org): ?OrgSubscription
    {
        return $org->subscription()->first() ?? $org->subscriptions()->first();
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
