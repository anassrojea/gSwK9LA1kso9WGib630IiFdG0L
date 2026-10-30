<?php

namespace App\Services\Journey;

use App\Models\AuditLog;
use App\Models\Bundle;
use App\Models\Order;
use App\Models\User;
use App\Services\Invoicing\Vat;
use App\Services\Payments\Currencies;
use App\Services\Payments\PaymentGateways;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buying a family journey bundle (phase 8D): an order without an event, with
 * one "bundle" line, paid through the same gateways and OrderFulfillment as
 * any order (BundleItem issues the credits when it is paid or approved).
 * Discount codes and the loyalty discount don't apply: a bundle is already
 * the discounted price.
 */
class BundleCheckout
{
    public function __construct(private PaymentGateways $gateways) {}

    public function place(User $user, Bundle $bundle, string $currency, string $gatewayId, ?string $country = null): Order
    {
        if (! $bundle->active) {
            throw ValidationException::withMessages(['bundle' => trans('journey.bundles.errors.unavailable')]);
        }
        $gateway = $this->gateways->find($gatewayId);
        if (! $gateway || ! $gateway->enabled()) {
            throw ValidationException::withMessages(['gateway' => trans('app.checkout.errors.gateway')]);
        }
        if (! Currencies::supported($currency)) {
            throw ValidationException::withMessages(['currency' => trans('app.checkout.errors.currency')]);
        }
        $charge = PaymentGateways::chargeCurrency($gateway, $currency);
        if ($charge === null) {
            throw ValidationException::withMessages(['gateway' => trans('app.checkout.errors.gateway_currency')]);
        }
        $price = $bundle->price($charge);
        if ($price === null || $price <= 0) {
            throw ValidationException::withMessages(['currency' => trans('app.checkout.errors.no_price', ['currency' => $charge])]);
        }

        return DB::transaction(function () use ($user, $bundle, $gateway, $charge, $currency, $country, $price) {
            $vat = Vat::checkoutSurcharge($price, $charge, $country ?? $user->country);

            $order = Order::query()->create([
                'user_id' => $user->getKey(),
                'event_id' => null,
                'items' => [[
                    'type' => 'bundle',
                    'id' => $bundle->id,
                    'name' => $bundle->name_en,
                    'quantity' => 1,
                    'unit_amount' => $price,
                    'amount' => $price,
                ]],
                'total_cents' => $price + $vat,
                'discount_cents' => 0,
                'vat_cents' => $vat,
                'currency' => $charge,
                'requested_currency' => $charge === $currency ? null : $currency,
                'method' => $gateway->method(),
                'gateway' => $gateway->id(),
                'status' => 'awaiting_payment',
                'expires_at' => $gateway->method() === 'transfer'
                    ? now()->addHours((int) config('payments.transfer.expires_after_hours', 48))
                    : null,
            ]);

            $user->forceFill(array_filter(['currency' => $currency, 'country' => $country]))->save();
            AuditLog::record('order.created', $order, ['gateway' => $order->gateway, 'total' => $order->total_cents, 'currency' => $order->currency, 'bundle' => $bundle->id], $user);

            return $order;
        });
    }
}
