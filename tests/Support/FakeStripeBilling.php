<?php

namespace Tests\Support;

use Stripe\ApiRequestor;

/**
 * FakeStripe plus the subscription calls Cashier makes for Venue Pro (phase 8A):
 * reading a subscription or its item (cancel at period end), updating one
 * (swap, cancel, resume), cancelling one now, and Billing Portal sessions.
 * Subscriptions answer with $subscriptions[id] (set by the test), else a default.
 */
class FakeStripeBilling extends FakeStripe
{
    /** @var array<string, array<string, mixed>> */
    public array $subscriptions = [];

    public static function install(): self
    {
        $fake = new self;
        ApiRequestor::setHttpClient($fake);

        return $fake;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = (string) parse_url($absUrl, PHP_URL_PATH);

        if (preg_match('#/v1/subscriptions/(sub_[A-Za-z0-9_]+)$#', $path, $m)) {
            $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];
            $sub = $this->subscription($m[1]);
            if ($method === 'post') {
                if (array_key_exists('cancel_at_period_end', $params)) {
                    $sub['cancel_at_period_end'] = filter_var($params['cancel_at_period_end'], FILTER_VALIDATE_BOOLEAN);
                }
                if (isset($params['items'][0]['price'])) {
                    $sub['items']['data'][0]['price']['id'] = $params['items'][0]['price'];
                }
                $this->subscriptions[$m[1]] = $sub;
            }
            if ($method === 'delete') {
                $sub['status'] = 'canceled';
            }

            return [json_encode($sub), 200, []];
        }

        if (preg_match('#/v1/subscription_items/(si_[A-Za-z0-9_]+)$#', $path, $m)) {
            $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];

            return [json_encode(['id' => $m[1], 'object' => 'subscription_item', 'current_period_end' => now()->addMonth()->timestamp, 'current_period_start' => now()->timestamp, 'price' => ['id' => 'price_x', 'object' => 'price', 'product' => 'prod_x']]), 200, []];
        }

        if (str_ends_with($path, '/v1/billing_portal/sessions')) {
            $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];

            return [json_encode(['id' => 'bps_1', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/test_portal']), 200, []];
        }

        return parent::request($method, $absUrl, $headers, $params, $hasFile, $apiMode, $maxNetworkRetries);
    }

    /**
     * @return array<string, mixed>
     */
    public function subscription(string $id): array
    {
        return $this->subscriptions[$id] ?? [
            'id' => $id,
            'object' => 'subscription',
            'customer' => 'cus_fake123',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_'.$id,
                'object' => 'subscription_item',
                'quantity' => 1,
                'current_period_end' => now()->addMonth()->timestamp,
                'price' => ['id' => 'price_venue_pro_usd', 'object' => 'price', 'product' => 'prod_venue', 'currency' => 'usd', 'unit_amount' => 9900, 'recurring' => ['interval' => 'month']],
            ]]],
        ];
    }
}
