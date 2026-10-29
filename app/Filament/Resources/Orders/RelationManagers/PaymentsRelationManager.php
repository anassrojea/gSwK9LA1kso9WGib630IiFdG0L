<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Models\Payment;
use App\Services\Payments\Currencies;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Money recorded for the order: the bank transfer on approval, or each card
 * gateway webhook (read-only; the raw payload stays in the database).
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return trans('app.admin.payments.title');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('provider')->label(trans('app.admin.payments.provider'))->badge(),
                TextColumn::make('provider_ref')->label(trans('app.admin.payments.reference'))->copyable()->limit(32),
                TextColumn::make('amount_cents')->label(trans('app.payments.amount'))
                    ->formatStateUsing(fn (Payment $record) => Currencies::format($record->amount_cents, $record->currency ?? $this->getOwnerRecord()->currency, app()->getLocale())),
                TextColumn::make('status')->label(trans('app.admin.orders.status'))->badge()
                    ->color(fn (string $state) => $state === 'succeeded' ? 'success' : 'danger'),
                TextColumn::make('created_at')->label(trans('app.payments.created'))->dateTime(),
            ]);
    }
}
