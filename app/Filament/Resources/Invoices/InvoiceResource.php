<?php

namespace App\Filament\Resources\Invoices;

use App\Filament\AdminNavigation;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Invoice;
use App\Services\Payments\Currencies;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Invoices, receipts and credit notes (phase 6B): read-only, filterable by
 * date, type and kind, with a PDF per row and a CSV export of the filtered
 * list for the accountant (ListInvoices). Documents are issued by
 * App\Services\Invoicing\Invoicer, never here.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'number';

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Sales;

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return trans('invoices.admin.label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('invoices.admin.plural');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['number'];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $money = fn (Invoice $record, int $amount) => Currencies::format($amount, $record->currency, app()->getLocale());

        return $table
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('number')->label(trans('invoices.number'))->searchable()->copyable()->weight('bold')->fontFamily('mono'),
                TextColumn::make('kind')->label(trans('invoices.admin.kind'))->badge()
                    ->formatStateUsing(fn (string $state) => trans("invoices.kinds.{$state}"))
                    ->color(fn (string $state) => $state === Invoice::KIND_CREDIT_NOTE ? 'danger' : 'gray'),
                TextColumn::make('type')->label(trans('invoices.admin.type'))->badge()
                    ->formatStateUsing(fn (string $state) => trans("invoices.types.{$state}"))
                    ->color(fn (string $state) => $state === Invoice::TYPE_TAX ? 'success' : 'gray'),
                TextColumn::make('issued_at')->label(trans('invoices.issued_at'))->dateTime()->sortable(),
                TextColumn::make('order.code')->label(trans('invoices.admin.order'))
                    ->url(fn (Invoice $record) => $record->order ? OrderResource::getUrl('view', ['record' => $record->order]) : null)
                    ->searchable(),
                TextColumn::make('customer.name')->label(trans('invoices.admin.customer'))
                    ->description(fn (Invoice $record) => $record->customer['email'] ?? null)
                    ->placeholder('—'),
                TextColumn::make('vat_cents')->label(trans('invoices.admin.vat'))
                    ->formatStateUsing(fn (Invoice $record) => $money($record, $record->vat_cents))
                    ->alignEnd(),
                TextColumn::make('total_cents')->label(trans('invoices.admin.total'))
                    ->formatStateUsing(fn (Invoice $record) => $money($record, $record->total_cents))
                    ->weight('bold')
                    ->alignEnd()
                    ->sortable(),
            ])
            ->filters([
                Filter::make('issued')
                    ->schema([
                        DatePicker::make('from')->label(trans('invoices.admin.issued_from')),
                        DatePicker::make('until')->label(trans('invoices.admin.issued_until')),
                    ])
                    ->query(fn (Builder $query, array $data) => static::issuedBetween($query, $data['from'] ?? null, $data['until'] ?? null))
                    ->indicateUsing(fn (array $data) => array_values(array_filter([
                        ($data['from'] ?? null) ? trans('invoices.admin.issued_from').' '.$data['from'] : null,
                        ($data['until'] ?? null) ? trans('invoices.admin.issued_until').' '.$data['until'] : null,
                    ]))),
                SelectFilter::make('type')->label(trans('invoices.admin.type'))
                    ->options(collect(Invoice::TYPES)->mapWithKeys(fn (string $t) => [$t => trans("invoices.types.{$t}")])->all()),
                SelectFilter::make('kind')->label(trans('invoices.admin.kind'))
                    ->options([
                        Invoice::KIND_INVOICE => trans('invoices.kinds.invoice'),
                        Invoice::KIND_CREDIT_NOTE => trans('invoices.kinds.credit_note'),
                    ]),
                SelectFilter::make('currency')->label(trans('app.checkout.currency'))
                    ->options(collect(Currencies::all())->mapWithKeys(fn (string $c) => [$c => $c])->all()),
            ])
            ->recordActions([
                static::downloadAction(),
            ]);
    }

    /**
     * Dates are the documents' own calendar days (INVOICE_TIMEZONE, Riyadh by default).
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public static function issuedBetween(Builder $query, ?string $from, ?string $until): Builder
    {
        $timezone = (string) config('invoicing.timezone', 'Asia/Riyadh');

        return $query
            ->when($from, fn (Builder $q) => $q->where('issued_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc()))
            ->when($until, fn (Builder $q) => $q->where('issued_at', '<=', CarbonImmutable::parse($until, $timezone)->endOfDay()->utc()));
    }

    public static function downloadAction(): Action
    {
        return Action::make('pdf')
            ->label(trans('invoices.admin.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->url(fn (Invoice $record) => route('invoices.download', $record))
            ->openUrlInNewTab();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
        ];
    }
}
