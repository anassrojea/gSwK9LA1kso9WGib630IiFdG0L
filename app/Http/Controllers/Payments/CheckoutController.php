<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Order;
use App\Services\Account\EmailVerification;
use App\Services\Payments\CheckoutFailed;
use App\Services\Payments\CheckoutService;
use App\Services\Payments\Currencies;
use App\Services\Payments\Discounts;
use App\Services\Payments\OrderFulfillment;
use App\Services\Payments\OrderUrls;
use App\Services\Payments\PaymentGateways;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /checkout {event_id, plan?, addons[{id, quantity}], currency, gateway, country?, discount_code?, from: wizard|dashboard}
 *
 * Creates the order (CheckoutService) and starts its gateway: a card method
 * redirects to the provider's page, a transfer goes to the bank details, and
 * a zero total (a 100% code) is paid at once without a gateway.
 */
class CheckoutController extends Controller
{
    public function store(Request $request, CheckoutService $checkout, PaymentGateways $gateways, OrderFulfillment $fulfillment): Response
    {
        // paying needs a verified email (drafts don't): the page shows the resend prompt
        EmailVerification::ensure($request, 'checkout');

        $data = $request->validate([
            'event_id' => ['required', 'uuid'],
            'plan' => ['nullable', 'string', 'max:40'],
            'addons' => ['array', 'max:20'],
            'addons.*.id' => ['required', 'string', 'max:40'],
            'addons.*.quantity' => ['nullable', 'integer', 'min:1', 'max:'.config('payments.max_quantity', 10)],
            'currency' => ['required', 'string', Rule::in(Currencies::all())],
            'gateway' => ['required', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:5'],
            'discount_code' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', Rule::in(['wizard', 'dashboard'])],
            // phase 8D: pay the plan with a bundle credit, spend account credit
            'bundle_credit' => ['nullable', 'uuid'],
            'use_credit' => ['nullable', 'boolean'],
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
            $data['discount_code'] ?? null,
            ['bundle_credit' => $data['bundle_credit'] ?? null, 'use_credit' => (bool) ($data['use_credit'] ?? false)],
        );

        $from = $data['from'] ?? 'dashboard';

        if ($order->total_cents === 0) {
            $fulfillment->fulfillFree($order);

            return redirect()->to($this->afterwards($order, $from))
                ->with('status', trans('app.checkout.free_done', ['code' => $order->code]));
        }

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

        return redirect()->to($this->afterwards($order, $from))
            ->with('status', trans('app.checkout.transfer_created', ['code' => $order->code]));
    }

    /**
     * POST /checkout/discount {event_id, code}: is the code usable by this host
     * now, and what does it take off (the checkout shows it; POST /checkout re-checks).
     */
    public function discount(Request $request, Discounts $discounts): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:40'],
        ]);

        Gate::authorize('update', Event::query()->findOrFail($data['event_id']));

        $code = $discounts->usable($data['code'], $request->user());

        return response()->json(['discount' => $discounts->present($code)]);
    }

    private function afterwards(Order $order, string $from): string
    {
        return $from === 'wizard' && $order->event_id
            ? route('create.step', [$order->event_id, 'review'])
            : route('dashboard.orders.show', $order);
    }
}
