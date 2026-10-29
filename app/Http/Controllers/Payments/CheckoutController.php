<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Payments\CheckoutFailed;
use App\Services\Payments\CheckoutService;
use App\Services\Payments\Currencies;
use App\Services\Payments\OrderUrls;
use App\Services\Payments\PaymentGateways;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /checkout {event_id, plan?, addons[{id, quantity}], currency, gateway, country?, from: wizard|dashboard}
 *
 * Creates the order (CheckoutService) and starts its gateway: a card method
 * redirects to the provider's page, a transfer goes to the bank details.
 */
class CheckoutController extends Controller
{
    public function store(Request $request, CheckoutService $checkout, PaymentGateways $gateways): Response
    {
        $data = $request->validate([
            'event_id' => ['required', 'uuid'],
            'plan' => ['nullable', 'string', 'max:40'],
            'addons' => ['array', 'max:20'],
            'addons.*.id' => ['required', 'string', 'max:40'],
            'addons.*.quantity' => ['nullable', 'integer', 'min:1', 'max:'.config('payments.max_quantity', 10)],
            'currency' => ['required', 'string', Rule::in(Currencies::all())],
            'gateway' => ['required', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:5'],
            'from' => ['nullable', Rule::in(['wizard', 'dashboard'])],
        ]);

        $event = Event::query()->findOrFail($data['event_id']);
        Gate::authorize('update', $event);

        $country = strtoupper((string) ($data['country'] ?? ''));
        $order = $checkout->place(
            $request->user(),
            $event,
            $data['plan'] ?? null,
            array_values($data['addons'] ?? []),
            $data['currency'],
            $data['gateway'],
            array_key_exists($country, config('payments.countries')) ? $country : null,
        );

        $from = $data['from'] ?? 'dashboard';

        try {
            $result = $gateways->get($order->gateway)->start($order, $from);
        } catch (CheckoutFailed) {
            return redirect()->to(OrderUrls::back($order, $from, 'failed'))
                ->with('warning', trans('app.checkout.errors.start_failed'));
        }

        if ($result->isRedirect()) {
            // Inertia visits leave the app with a 409 + X-Inertia-Location
            return Inertia::location($result->redirectUrl);
        }

        return $this->afterTransfer($order, $from);
    }

    private function afterTransfer($order, string $from): RedirectResponse
    {
        $url = $from === 'wizard' && $order->event_id
            ? route('create.step', [$order->event_id, 'review'])
            : route('dashboard.orders.show', $order);

        return redirect()->to($url)->with('status', trans('app.checkout.transfer_created', ['code' => $order->code]));
    }
}
