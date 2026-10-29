<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The customer's "I sent the receipt" and the admin's decision on it (read-only).
 */
class TransferReceiptsRelationManager extends RelationManager
{
    protected static string $relationship = 'transferReceipts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return trans('app.admin.receipts.title');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('received_via')->label(trans('app.admin.receipts.via'))->badge(),
                TextColumn::make('received_at')->label(trans('app.admin.receipts.received_at'))->dateTime(),
                TextColumn::make('note')->label(trans('app.admin.actions.note'))->placeholder('—')->wrap(),
                TextColumn::make('decision')->label(trans('app.admin.receipts.decision'))->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? trans("app.payments.status.{$state}") : null)
                    ->color(fn (?string $state) => $state === 'approved' ? 'success' : 'danger')
                    ->placeholder('—'),
                TextColumn::make('bank_reference')->label(trans('app.admin.actions.bank_reference'))->copyable()->placeholder('—'),
                TextColumn::make('reason')->label(trans('app.admin.actions.reason'))->placeholder('—')->wrap(),
                TextColumn::make('approver.name')->label(trans('app.admin.receipts.decided_by'))->placeholder('—'),
                TextColumn::make('decided_at')->label(trans('app.admin.receipts.decided_at'))->dateTime()->placeholder('—'),
            ]);
    }
}
