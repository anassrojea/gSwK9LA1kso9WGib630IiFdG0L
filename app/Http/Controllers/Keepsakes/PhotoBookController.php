<?php

namespace App\Http\Controllers\Keepsakes;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\PhotoBook;
use App\Services\Account\EmailVerification;
use App\Services\Album\Album;
use App\Services\Keepsakes\PhotoBookPdf;
use App\Services\Keepsakes\PhotoBooks;
use App\Services\Payments\CheckoutFailed;
use App\Services\Payments\Currencies;
use App\Services\Payments\OrderFulfillment;
use App\Services\Payments\OrderUrls;
use App\Services\Payments\PaymentGateways;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The printed photo book (phase 8D):
 *
 *   PUT  /events/{event}/photo-book           the draft: title, layout, photos, shipping address
 *   POST /events/{event}/photo-book/preview   render the print-ready PDF (Chromium)
 *   POST /events/{event}/photo-book/order     {currency, gateway, country?}: the photo_book order, then its gateway
 *   GET  /photo-books/{book}.pdf              the PDF, for the host and admins
 */
class PhotoBookController extends Controller
{
    public function update(Request $request, Event $event, PhotoBooks $books): RedirectResponse
    {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'subtitle' => ['nullable', 'string', 'max:120'],
            'size' => ['required', Rule::in(PhotoBook::SIZES)],
            'pages' => ['required', 'integer', Rule::in(PhotoBook::PAGES)],
            'auto_pick' => ['required', 'boolean'],
            'photo_ids' => ['array', 'max:200'],
            'photo_ids.*' => ['uuid'],
            'copies' => ['required', 'integer', 'min:1', 'max:'.(int) config('payments.max_quantity', 10)],
            'ship_name' => ['nullable', 'string', 'max:120'],
            'ship_country' => ['nullable', 'string', 'size:2'],
            'ship_city' => ['nullable', 'string', 'max:80'],
            'ship_address' => ['nullable', 'string', 'max:255'],
            'ship_phone' => ['nullable', 'string', 'max:32'],
        ]);
        if (isset($data['ship_country'])) {
            $data['ship_country'] = strtoupper($data['ship_country']);
        }

        $books->update($books->draft($event, $request->user()), $data + ['photo_ids' => $data['photo_ids'] ?? []]);

        return back()->with('status', trans('keepsakes.photo_book.saved'));
    }

    public function preview(Request $request, Event $event, PhotoBooks $books, PhotoBookPdf $pdf): RedirectResponse
    {
        Gate::authorize('update', $event);
        $book = $books->draft($event, $request->user());

        if (! $pdf->available()) {
            return back()->with('warning', trans('keepsakes.photo_book.errors.renderer'));
        }

        try {
            $pdf->generate($book);
        } catch (Throwable $e) {
            report($e);

            return back()->with('warning', trans('keepsakes.photo_book.errors.render_failed'));
        }

        return back()->with('status', trans('keepsakes.photo_book.pdf_ready', ['pages' => $book->fresh()->pdf_pages]));
    }

    public function order(Request $request, Event $event, PhotoBooks $books, PaymentGateways $gateways, OrderFulfillment $fulfillment): Response
    {
        Gate::authorize('update', $event);
        EmailVerification::ensure($request, 'checkout');

        $data = $request->validate([
            'currency' => ['required', 'string', Rule::in(Currencies::all())],
            'gateway' => ['required', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:5'],
        ]);
        $country = strtoupper((string) ($data['country'] ?? ''));

        $order = $books->order($books->draft($event, $request->user()), $request->user(), $data['currency'], $data['gateway'], array_key_exists($country, config('payments.countries')) ? $country : null);

        if ($order->total_cents === 0) {
            $fulfillment->fulfillFree($order);

            return redirect()->route('dashboard.orders.show', $order);
        }

        try {
            $result = $gateways->get($order->gateway)->start($order, 'dashboard');
        } catch (CheckoutFailed) {
            return redirect()->to(OrderUrls::back($order, 'dashboard', 'failed'))->with('warning', trans('app.checkout.errors.start_failed'));
        }

        return $result->isRedirect()
            ? Inertia::location($result->redirectUrl)
            : redirect()->route('dashboard.orders.show', $order)->with('status', trans('app.checkout.transfer_created', ['code' => $order->code]));
    }

    public function pdf(Request $request, PhotoBook $book, Album $album): Response
    {
        Gate::authorize('view', $book->event);
        $disk = $album->disk();
        abort_if(! $book->pdf_key || ! $disk->exists($book->pdf_key), 404);

        return $disk->response($book->pdf_key, 'photo-book-'.$book->pages.'.pdf', ['Cache-Control' => 'private, no-store']);
    }
}
