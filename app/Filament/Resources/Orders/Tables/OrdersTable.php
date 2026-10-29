<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Filament\Resources\Orders\OrderActions;
use App\Models\Order;
use App\Services\Payments\Currencies;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public const STATUS_COLORS = [
        'awaiting_payment' => 'gray',
        'receipt_received' => 'warning',
        'paid' => 'success',
        'approved' => 'success',
        'rejected' => 'danger',
        'expired' => 'gray',
        'refunded' => 'info',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->label(trans('app.payments.code'))
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),
                TextColumn::make('user.name')
                    ->label(trans('app.admin.orders.customer'))
                    // purged accounts leave their orders without a customer
                    ->placeholder(trans('admin.users.deleted_account'))
                    ->description(fn (Order $record) => $record->user?->email)
                    ->searchable(),
                TextColumn::make('event.title')
                    ->label(trans('app.admin.orders.event'))
                    ->limit(30)
                    ->toggleable(),
                TextColumn::make('items')
                    ->label(trans('app.payments.items'))
                    ->state(fn (Order $record) => collect($record->items)->map(fn ($i) => $i['id'].(($i['quantity'] ?? 1) > 1 ? ' ×'.$i['quantity'] : ''))->implode(', '))
                    ->toggleable(),
                TextColumn::make('total_cents')
                    ->label(trans('app.payments.amount'))
                    ->formatStateUsing(fn (Order $record) => Currencies::format($record->total_cents, $record->currency, app()->getLocale()))
                    ->sortable(),
                TextColumn::make('gateway')
                    ->label(trans('app.admin.orders.method'))
                    ->formatStateUsing(fn (string $state) => trans("app.payments.gateways.{$state}"))
                    ->badge()
                    ->color(fn (string $state) => $state === 'transfer' ? 'warning' : 'info'),
                TextColumn::make('status')
                    ->label(trans('app.admin.orders.status'))
                    ->formatStateUsing(fn (string $state) => trans("app.payments.status.{$state}"))
                    ->badge()
                    ->color(fn (string $state) => self::STATUS_COLORS[$state] ?? 'gray'),
                TextColumn::make('latestReceipt.received_at')
                    ->label(trans('app.admin.orders.receipt_at'))
                    ->dateTime()
                    ->since()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(trans('app.payments.created'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label(trans('app.payments.expires'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('paid_at')
                    ->label(trans('app.payments.paid_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(trans('app.admin.orders.status'))
                    ->multiple()
                    ->options(collect(Order::STATUSES)->mapWithKeys(fn ($s) => [$s => trans("app.payments.status.{$s}")])->all()),
                SelectFilter::make('gateway')
                    ->label(trans('app.admin.orders.method'))
                    ->options(collect(array_keys(config('payments.gateways')))->mapWithKeys(fn ($g) => [$g => trans("app.payments.gateways.{$g}")])->all()),
                SelectFilter::make('currency')
                    ->label(trans('app.checkout.currency'))
                    ->options(collect(Currencies::all())->mapWithKeys(fn ($c) => [$c => $c])->all()),
            ])
            ->recordActions([
                OrderActions::approve(),
                ActionGroup::make([
                    ViewAction::make(),
                    OrderActions::reject(),
                    OrderActions::whatsapp(),
                    OrderActions::refund(),
                ]),
            ]);
    }
}
