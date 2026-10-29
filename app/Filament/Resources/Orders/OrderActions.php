<?php

namespace App\Filament\Resources\Orders;

use App\Models\Order;
use App\Services\Payments\Currencies;
use App\Services\Payments\Gateways\TransferGateway;
use App\Services\Payments\OrderFulfillment;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The admin's decisions on an order, shared by the table rows and the order
 * page. Each one goes through OrderFulfillment (audit log, entitlements,
 * customer email). Only admins reach the panel, and OrderFulfillment checks
 * the role again.
 */
class OrderActions
{
    public static function approve(): Action
    {
        return Action::make('approve')
            ->label(trans('app.admin.actions.approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Order $record) => $record->isTransfer() && in_array($record->status, [...Order::PENDING, 'expired'], true))
            ->requiresConfirmation()
            ->modalHeading(fn (Order $record) => trans('app.admin.actions.approve_heading', ['code' => $record->code]))
            ->modalDescription(fn (Order $record) => trans('app.admin.actions.approve_warning', [
                'amount' => Currencies::format($record->total_cents, $record->currency, app()->getLocale()),
            ]))
            ->modalSubmitActionLabel(trans('app.admin.actions.approve_submit'))
            ->schema([
                TextInput::make('bank_reference')
                    ->label(trans('app.admin.actions.bank_reference'))
                    ->helperText(trans('app.admin.actions.bank_reference_help'))
                    ->required()
                    ->maxLength(64)
                    // a plain rule: Filament's unique() would also ignore the order's own id
                    ->rules([Rule::unique('transfer_receipts', 'bank_reference')])
                    ->validationMessages(['unique' => trans('app.payments.errors.duplicate_reference')]),
                Textarea::make('note')
                    ->label(trans('app.admin.actions.note'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (Order $record, array $data, Action $action) {
                static::run($action, fn () => app(OrderFulfillment::class)->approveTransfer($record, auth()->user(), $data['bank_reference'], $data['note'] ?? null));

                $record->refresh();
                Notification::make()
                    ->title(trans('app.admin.actions.approved', ['code' => $record->code]))
                    ->body(trans('app.admin.actions.approved_body'))
                    ->success()
                    ->actions([
                        Action::make('whatsapp')
                            ->label(trans('app.admin.actions.whatsapp'))
                            ->url(static::whatsappConfirmationUrl($record), shouldOpenInNewTab: true),
                    ])
                    ->persistent()
                    ->send();
            });
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label(trans('app.admin.actions.reject'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Order $record) => $record->isTransfer() && in_array($record->status, [...Order::PENDING, 'expired'], true))
            ->requiresConfirmation()
            ->modalHeading(fn (Order $record) => trans('app.admin.actions.reject_heading', ['code' => $record->code]))
            ->modalDescription(trans('app.admin.actions.reject_text'))
            ->schema([
                Textarea::make('reason')
                    ->label(trans('app.admin.actions.reason'))
                    ->helperText(trans('app.admin.actions.reason_help'))
                    ->required()
                    ->rows(3)
                    ->maxLength(500),
            ])
            ->action(function (Order $record, array $data, Action $action) {
                static::run($action, fn () => app(OrderFulfillment::class)->rejectTransfer($record, auth()->user(), $data['reason']));

                Notification::make()->title(trans('app.admin.actions.rejected', ['code' => $record->code]))->success()->send();
            });
    }

    public static function refund(): Action
    {
        return Action::make('refund')
            ->label(trans('app.admin.actions.refund'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Order $record) => $record->isFulfilled())
            ->requiresConfirmation()
            ->modalHeading(fn (Order $record) => trans('app.admin.actions.refund_heading', ['code' => $record->code]))
            ->modalDescription(trans('app.admin.actions.refund_warning'))
            ->schema([
                Textarea::make('reason')->label(trans('app.admin.actions.reason'))->rows(2)->maxLength(500),
            ])
            ->action(function (Order $record, array $data, Action $action) {
                static::run($action, fn () => app(OrderFulfillment::class)->refund($record, auth()->user(), $data['reason'] ?? null));

                Notification::make()->title(trans('app.admin.actions.refunded', ['code' => $record->code]))->success()->send();
            });
    }

    /**
     * After approval: a wa.me link the admin taps to confirm to the customer.
     */
    public static function whatsapp(): Action
    {
        return Action::make('whatsapp')
            ->label(trans('app.admin.actions.whatsapp'))
            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->color('success')
            ->visible(fn (Order $record) => $record->isFulfilled())
            ->url(fn (Order $record) => static::whatsappConfirmationUrl($record), shouldOpenInNewTab: true);
    }

    public static function whatsappConfirmationUrl(Order $order): string
    {
        $order->loadMissing('user', 'event');
        $locale = $order->user?->preferredLocale() ?? 'ar';
        $phone = preg_replace('/\D+/', '', (string) $order->user?->phone);

        $text = trans('app.admin.actions.whatsapp_message', [
            'name' => (string) $order->user?->name,
            'code' => $order->code,
            'amount' => Currencies::format($order->total_cents, $order->currency, $locale),
            'items' => TransferGateway::itemsLabel($order, $locale),
            'link' => route('dashboard.orders.show', $order),
        ], $locale);

        return 'https://wa.me/'.$phone.'?text='.rawurlencode($text);
    }

    /**
     * Business rule failures become a notification instead of a crash.
     */
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
