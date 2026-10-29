<?php

namespace App\Services\Payments;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The gateways listed in config/payments.php, and the currency rule shared
 * by the checkout, the UI and the tests:
 *
 *   a gateway charges the host's currency when it supports it, otherwise the
 *   fallback price list (USD) when it supports that, otherwise it is not
 *   offered for that currency.
 *
 * Bank transfer takes SYP, SAR and USD (one bank account each). Stripe takes
 * SAR and USD (it does not serve Syria), so SYP becomes USD. Lemon Squeezy
 * charges in its store currency (USD), so SYP and SAR become USD.
 */
class PaymentGateways
{
    /** @var array<string, PaymentGateway> */
    private array $resolved = [];

    public function __construct(private Container $container) {}

    /**
     * @return array<string, PaymentGateway>
     */
    public function all(): array
    {
        foreach (config('payments.gateways', []) as $id => $class) {
            $this->resolved[$id] ??= $this->container->make($class);
        }

        return array_intersect_key($this->resolved, config('payments.gateways', []));
    }

    /**
     * @return array<string, PaymentGateway>
     */
    public function enabled(): array
    {
        return array_filter($this->all(), fn (PaymentGateway $gateway) => $gateway->enabled());
    }

    public function find(string $id): ?PaymentGateway
    {
        return $this->all()[$id] ?? null;
    }

    public function get(string $id): PaymentGateway
    {
        return $this->find($id) ?? throw new InvalidArgumentException("Unknown payment gateway [{$id}].");
    }

    /**
     * The gateway behind a webhook path segment ("lemon-squeezy" → lemon_squeezy).
     */
    public function forWebhook(string $slug): ?PaymentGateway
    {
        return $this->find(str_replace('-', '_', $slug));
    }

    /**
     * The currency the gateway charges when the host chose $requested, or null when it can't charge it at all.
     */
    public static function chargeCurrency(PaymentGateway $gateway, string $requested): ?string
    {
        if ($gateway->supportsCurrency($requested)) {
            return $requested;
        }

        $fallback = Currencies::fallback();

        return $gateway->supportsCurrency($fallback) ? $fallback : null;
    }
}
