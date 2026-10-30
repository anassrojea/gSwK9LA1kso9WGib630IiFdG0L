<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Services\Invoicing\InvoiceCsv;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // the list as filtered now, for the accountant
            Action::make('export')
                ->label(trans('invoices.admin.export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn () => $this->exportCsv()),
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        $query = $this->getFilteredTableQuery() ?? Invoice::query();

        AuditLog::record('invoice.exported', auth()->user(), ['count' => (clone $query)->count()]);

        return app(InvoiceCsv::class)->download($query, 'invoices-'.now()->format('Y-m-d').'.csv');
    }
}
