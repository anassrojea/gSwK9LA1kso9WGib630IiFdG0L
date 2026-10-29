<?php

namespace App\Services\Payments;

use App\Models\Order;

/**
 * One way to pay (plan section 12.1). Implementations are listed in
 * config/payments.php "gateways"; a new local gateway (Tap, Moyasar,
 * HyperPay, Paymob) is one class implementing this, plus HandlesWebhooks
 * when it reports payments back (served at POST /webhooks/{id with dashes}).
 * Money received goes through OrderFulfillment, never straight to the order.
 */
interface PaymentGateway
{
    /** Stable id stored in orders.gateway, e.g. "transfer", "stripe". */
    public function id(): string;

    /** The orders.method it creates: "transfer", "card" or "wallet_local". */
    public function method(): string;

    /** Switched on in config and fully configured (keys present). The checkout only lists enabled gateways. */
    public function enabled(): bool;

    /**
     * The currencies it can charge.
     *
     * @return list<string>
     */
    public function currencies(): array;

    public function supportsCurrency(string $currency): bool;

    /**
     * Begin paying an order: a redirect to the provider's checkout, or the
     * instructions to show (bank transfer). $returnTo: "wizard" or "dashboard",
     * where the customer comes back to.
     *
     * @throws CheckoutFailed when the provider can't be reached
     */
    public function start(Order $order, string $returnTo = 'dashboard'): CheckoutResult;
}
