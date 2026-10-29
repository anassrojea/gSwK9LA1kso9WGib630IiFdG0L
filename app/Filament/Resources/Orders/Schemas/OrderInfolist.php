<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use App\Services\Payments\Currencies;
use App\Services\Payments\OrderPresenter;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The order page: what was bought at which price (the snapshot taken at
 * checkout), who and when. Receipts and payments are listed below it.
 */
class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $money = fn (Order $record, $amount) => Currencies::format((int) $amount, $record->currency, app()->getLocale());

        return $schema->components([
            Section::make(trans('app.admin.orders.label'))
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextEntry::make('code')->label(trans('app.payments.code'))->copyable()->weight('bold')->size('lg'),
                    TextEntry::make('status')->label(trans('app.admin.orders.status'))
                        ->formatStateUsing(fn (string $state) => trans("app.payments.status.{$state}"))
                        ->badge()->color(fn (string $state) => OrdersTable::STATUS_COLORS[$state] ?? 'gray'),
                    TextEntry::make('gateway')->label(trans('app.admin.orders.method'))->formatStateUsing(fn (string $state) => trans("app.payments.gateways.{$state}")),
                    TextEntry::make('total_cents')->label(trans('app.payments.amount'))->formatStateUsing(fn (Order $record) => $money($record, $record->total_cents))->weight('bold'),
                    TextEntry::make('discount_code')->label(trans('admin.discounts.label'))
                        ->formatStateUsing(fn (Order $record) => $record->discount_code.' · −'.$money($record, $record->discount_cents))
                        ->badge()->color('success')
                        ->placeholder('—'),
                    TextEntry::make('requested_currency')->label(trans('app.admin.orders.requested_currency'))->placeholder('—'),
                    TextEntry::make('gateway_ref')->label(trans('app.admin.orders.gateway_ref'))->placeholder('—')->copyable(),
                    TextEntry::make('user.name')->label(trans('app.admin.orders.customer'))->placeholder(trans('admin.users.deleted_account')),
                    TextEntry::make('user.email')->label(trans('app.email'))->placeholder('—'),
                    TextEntry::make('user.phone')->label(trans('app.admin.orders.phone'))->placeholder('—'),
                    TextEntry::make('event.title')->label(trans('app.admin.orders.event'))->placeholder('—'),
                    TextEntry::make('event.plan_id')->label(trans('app.admin.orders.event_plan'))->placeholder('free'),
                    TextEntry::make('created_at')->label(trans('app.payments.created'))->dateTime(),
                    TextEntry::make('expires_at')->label(trans('app.payments.expires'))->dateTime()->placeholder('—'),
                    TextEntry::make('paid_at')->label(trans('app.payments.paid_at'))->dateTime()->placeholder('—'),
                    TextEntry::make('refunded_at')->label(trans('app.payments.status.refunded'))->dateTime()->placeholder('—'),
                    TextEntry::make('rejection_reason')->label(trans('app.admin.actions.reason'))->placeholder('—')->columnSpanFull(),
                ]),
            Section::make(trans('app.payments.items'))
                ->schema([
                    RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->state(fn (Order $record) => collect($record->items)->map(fn (array $item) => [
                            'type' => trans($item['type'] === 'plan' ? 'app.checkout.plan' : 'app.admin.addons.label'),
                            'name' => app(OrderPresenter::class)->itemName($item, app()->getLocale()),
                            'quantity' => $item['quantity'] ?? 1,
                            'amount' => $money($record, $item['amount'] ?? 0),
                        ])->all())
                        ->columns(['default' => 2, 'md' => 4])
                        ->schema([
                            TextEntry::make('type')->label(trans('app.admin.orders.type')),
                            TextEntry::make('name')->label(trans('app.name_label')),
                            TextEntry::make('quantity')->label(trans('app.checkout.quantity')),
                            TextEntry::make('amount')->label(trans('app.payments.amount')),
                        ]),
                ]),
        ]);
    }
}
