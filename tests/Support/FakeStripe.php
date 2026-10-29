<?php

namespace Tests\Support;

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Stands in for Stripe's API: records each call and answers like Stripe for
 * the two Cashier makes during a one-time checkout (customer, checkout session).
 */
class FakeStripe implements ClientInterface
{
    /** @var list<array{method: string, url: string, params: array}> */
    public array $requests = [];

    public bool $fail = false;

    public static function install(): self
    {
        $fake = new self;
        ApiRequestor::setHttpClient($fake);

        return $fake;
    }

    public static function uninstall(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];

        if ($this->fail) {
            return [json_encode(['error' => ['type' => 'api_error', 'message' => 'Stripe is down (fake)']]), 500, []];
        }

        if (str_ends_with(parse_url($absUrl, PHP_URL_PATH), '/v1/customers')) {
            return [json_encode(['id' => 'cus_fake123', 'object' => 'customer', 'email' => $params['email'] ?? null]), 200, []];
        }

        if (str_ends_with(parse_url($absUrl, PHP_URL_PATH), '/v1/checkout/sessions')) {
            $id = 'cs_test_'.($params['client_reference_id'] ?? 'x');

            return [json_encode([
                'id' => $id,
                'object' => 'checkout.session',
                'mode' => $params['mode'] ?? 'payment',
                'payment_status' => 'unpaid',
                'url' => 'https://checkout.stripe.com/c/pay/'.$id,
                'amount_total' => $params['line_items'][0]['price_data']['unit_amount'] ?? null,
                'currency' => $params['line_items'][0]['price_data']['currency'] ?? null,
            ]), 200, []];
        }

        return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => "Unexpected {$method} {$absUrl}"]]), 404, []];
    }

    public function last(string $path): ?array
    {
        foreach (array_reverse($this->requests) as $request) {
            if (str_ends_with(parse_url($request['url'], PHP_URL_PATH), $path)) {
                return $request;
            }
        }

        return null;
    }
}
