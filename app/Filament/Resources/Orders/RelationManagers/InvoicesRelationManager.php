<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Services\Payments\Currencies;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The order's invoice or receipt and, after a refund, its credit note (phase 6B), with their PDFs.
 */
class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return trans('invoices.admin.plural');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('issued_at')
            ->columns([
                TextColumn::make('number')->label(trans('invoices.number'))->copyable()->weight('bold')->fontFamily('mono'),
                TextColumn::make('kind')->label(trans('invoices.admin.kind'))->badge()
                    ->formatStateUsing(fn (string $state) => trans("invoices.kinds.{$state}"))
                    ->color(fn (string $state) => $state === Invoice::KIND_CREDIT_NOTE ? 'danger' : 'gray'),
                TextColumn::make('type')->label(trans('invoices.admin.type'))->badge()
                    ->formatStateUsing(fn (string $state) => trans("invoices.types.{$state}")),
                TextColumn::make('vat_cents')->label(trans('invoices.admin.vat'))
                    ->formatStateUsing(fn (Invoice $record) => Currencies::format($record->vat_cents, $record->currency, app()->getLocale())),
                TextColumn::make('total_cents')->label(trans('invoices.admin.total'))
                    ->formatStateUsing(fn (Invoice $record) => Currencies::format($record->total_cents, $record->currency, app()->getLocale())),
                TextColumn::make('issued_at')->label(trans('invoices.issued_at'))->dateTime(),
            ])
            ->recordActions([
                InvoiceResource::downloadAction(),
            ]);
    }
}
