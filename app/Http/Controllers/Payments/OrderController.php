<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\CheckoutFailed;
use App\Services\Payments\OrderFulfillment;
use App\Services\Payments\OrderPresenter;
use App\Services\Payments\PaymentGateways;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The host's orders. Someone else's order is a 404, never a 403, so order
 * codes can't be probed.
 */
class OrderController extends Controller
{
    public function __construct(private OrderPresenter $presenter) {}

    /**
     * GET /dashboard/orders
     */
    public function index(Request $request): Response
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->getKey())
            ->with(['event:id,title,status', 'invoices'])
            ->latest()
            ->limit(200)
            ->get();

        return Inertia::render('orders/Index', [
            'orders' => $orders->map(fn (Order $o) => $this->presenter->present($o, details: false))->values(),
        ]);
    }

    /**
     * GET /dashboard/orders/{code}[?checkout=success|cancelled|failed]
     */
    public function show(Request $request, Order $order): Response
    {
        $this->authorizeOwner($request, $order);
        $order->load('latestReceipt');

        return Inertia::render('orders/Show', [
            'order' => $this->presenter->present($order),
            'checkout' => $request->query('checkout'),
        ]);
    }

    /**
     * GET /orders/{code}/status: polled by the page after a card checkout,
     * until the webhook has marked the order paid.
     */
    public function status(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOwner($request, $order);

        return response()->json(['code' => $order->code, 'status' => $order->status, 'paid_at' => $order->paid_at?->toIso8601String()])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * POST /orders/{code}/receipt {note?}: "I sent the receipt on WhatsApp".
     */
    public function receipt(Request $request, Order $order, OrderFulfillment $fulfillment): RedirectResponse
    {
        $this->authorizeOwner($request, $order);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $fulfillment->receiptSent($order, $request->user(), $data['note'] ?? null);

        return back()->with('status', trans('app.payments.transfer.receipt_marked'));
    }

    /**
     * POST /orders/{code}/retry: a fresh card checkout for an unpaid card order.
     */
    public function retry(Request $request, Order $order, PaymentGateways $gateways): HttpResponse
    {
        $this->authorizeOwner($request, $order);
        abort_unless($order->method === 'card' && $order->status === 'awaiting_payment', 422);

        $gateway = $gateways->find($order->gateway);
        if (! $gateway?->enabled()) {
            return back()->with('warning', trans('app.checkout.errors.gateway'));
        }

        try {
            $result = $gateway->start($order, $request->input('from') === 'wizard' ? 'wizard' : 'dashboard');
        } catch (CheckoutFailed) {
            return back()->with('warning', trans('app.checkout.errors.start_failed'));
        }

        return $result->isRedirect() ? Inertia::location($result->redirectUrl) : back();
    }

    private function authorizeOwner(Request $request, Order $order): void
    {
        abort_unless($order->user_id === $request->user()->getKey(), 404);
    }
}
