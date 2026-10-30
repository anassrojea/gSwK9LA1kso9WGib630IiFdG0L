<?php

namespace App\Http\Controllers\Invoices;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Invoicing\InvoicePdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * GET /invoices/{number}.pdf: the document's PDF, rendered on first download
 * and cached (InvoicePdf). The customer sees only their own (InvoicePolicy);
 * admins see every one. Anyone else gets a 404, so numbers can't be probed.
 */
class InvoiceController extends Controller
{
    public function download(Request $request, Invoice $invoice, InvoicePdf $pdf): StreamedResponse|RedirectResponse
    {
        abort_unless(Gate::allows('view', $invoice), 404);

        try {
            $path = $pdf->ensure($invoice);
        } catch (Throwable $e) {
            Log::warning('Invoice PDF could not be rendered.', ['invoice' => $invoice->number, 'error' => $e->getMessage()]);

            return back()->with('warning', trans('invoices.not_ready'));
        }

        return $pdf->disk()->download($path, $pdf->filename($invoice), [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
