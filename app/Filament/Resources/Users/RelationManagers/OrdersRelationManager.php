<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use App\Services\Payments\Currencies;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Orders of a customer or an event (read-only here; decisions are on the order page).
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
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Order $record) => OrderResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('code')->label(trans('app.payments.code'))->weight('bold')->copyable(),
                TextColumn::make('event.title')->label(trans('app.admin.orders.event'))->limit(30)->placeholder('—'),
                TextColumn::make('items')->label(trans('app.payments.items'))
                    ->state(fn (Order $record) => collect($record->items)->map(fn ($i) => $i['id'].(($i['quantity'] ?? 1) > 1 ? ' ×'.$i['quantity'] : ''))->implode(', ')),
                TextColumn::make('total_cents')->label(trans('app.payments.amount'))
                    ->formatStateUsing(fn (Order $record) => Currencies::format($record->total_cents, $record->currency, app()->getLocale())),
                TextColumn::make('discount_code')->label(trans('admin.discounts.label'))->placeholder('—')->badge()->color('success')->toggleable(),
                TextColumn::make('gateway')->label(trans('app.admin.orders.method'))
                    ->formatStateUsing(fn (string $state) => trans("app.payments.gateways.{$state}"))->badge(),
                TextColumn::make('status')->label(trans('app.admin.orders.status'))
                    ->formatStateUsing(fn (string $state) => trans("app.payments.status.{$state}"))
                    ->badge()->color(fn (string $state) => OrdersTable::STATUS_COLORS[$state] ?? 'gray'),
                TextColumn::make('created_at')->label(trans('app.payments.created'))->dateTime(),
            ]);
    }
}
