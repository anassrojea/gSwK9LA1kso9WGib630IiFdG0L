<?php

namespace App\Filament\Resources\Organizations\RelationManagers;

use App\Filament\Resources\Orders\OrderActions;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use App\Services\Payments\Currencies;
use App\Services\Payments\OrderPresenter;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The organization's orders: subscription periods (transfer renewals are
 * approved or rejected here with the usual order actions) and its events' orders.
 */
class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return trans('app.admin.orders.plural');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Order $record) => OrderResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('code')->label(trans('app.payments.code'))->weight('bold')->copyable(),
                TextColumn::make('items')->label(trans('app.payments.items'))
                    ->state(fn (Order $record) => collect($record->items)->map(fn ($i) => app(OrderPresenter::class)->itemName($i, app()->getLocale()))->implode(', ')),
                TextColumn::make('total_cents')->label(trans('app.payments.amount'))
                    ->formatStateUsing(fn (Order $record) => Currencies::format($record->total_cents, $record->currency, app()->getLocale())),
                TextColumn::make('gateway')->label(trans('app.admin.orders.method'))
                    ->formatStateUsing(fn (string $state) => trans("app.payments.gateways.{$state}"))->badge(),
                TextColumn::make('status')->label(trans('app.admin.orders.status'))
                    ->formatStateUsing(fn (string $state) => trans("app.payments.status.{$state}"))
                    ->badge()->color(fn (string $state) => OrdersTable::STATUS_COLORS[$state] ?? 'gray'),
                TextColumn::make('created_at')->label(trans('app.payments.created'))->dateTime(),
            ])
            ->recordActions([
                OrderActions::approve(),
                OrderActions::reject(),
            ]);
    }
}
