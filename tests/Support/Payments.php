<?php

namespace Tests\Support;

use App\Models\Event;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use Database\Factories\InvitationFactory;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlanSeeder;

/**
 * Payments test helpers. Nothing here reaches Stripe or Lemon Squeezy: Stripe's
 * HTTP client is replaced (FakeStripe), Lemon Squeezy goes through Http::fake,
 * and webhooks are signed locally with the test secrets below.
 */
class Payments
{
    public const STRIPE_WEBHOOK_SECRET = 'whsec_test_daawa';

    public const LS_SIGNING_SECRET = 'ls_signing_test';

    /**
     * Plans, prices and catalog seeded; all three gateways configured.
     */
    public static function setUp(): void
    {
        test()->seed([PlanSeeder::class, CatalogSeeder::class]);

        config([
            'payments.whatsapp_number' => '+963 912 345 678',
            'payments.transfer.enabled' => true,
            'payments.transfer.accounts' => [
                'SYP' => ['account_name' => 'Daawa', 'bank' => 'Bank of Syria', 'account_number' => 'SY-0001', 'note' => null, 'iban' => null, 'swift' => null],
                'SAR' => ['account_name' => 'Daawa LLC', 'bank' => 'Al Rajhi', 'iban' => 'SA0380000000608010167519', 'account_number' => null, 'swift' => null, 'note' => null],
                'USD' => ['account_name' => 'Daawa LLC', 'bank' => 'Al Rajhi', 'iban' => 'SA4420000001234567891234', 'account_number' => null, 'swift' => 'RJHISARI', 'note' => null],
            ],
            'cashier.key' => 'pk_test_daawa',
            'cashier.secret' => 'sk_test_daawa',
            'cashier.webhook.secret' => self::STRIPE_WEBHOOK_SECRET,
            'payments.stripe.enabled' => true,
            'lemon-squeezy.api_key' => 'ls_test_key',
            'lemon-squeezy.store' => '12345',
            'lemon-squeezy.signing_secret' => self::LS_SIGNING_SECRET,
            'payments.lemon_squeezy.enabled' => true,
            'payments.lemon_squeezy.variant_id' => '67890',
        ]);
    }

    /**
     * An event owned by $owner with an invitation in the given design.
     */
    public static function event(?User $owner = null, array $attributes = [], string $template = 'layali-envelope'): Event
    {
        $owner ??= User::factory()->create();
        $event = Event::factory()->draft()->create(['owner_id' => $owner->id] + $attributes);
        Invitation::factory()->for($event)->create(['config' => InvitationFactory::sampleConfig($template), 'published_at' => null]);

        return $event->fresh();
    }

    /**
     * A config for a Free invitation: the free envelope opening and a free theme.
     */
    public static function freeConfig(): array
    {
        $config = InvitationFactory::sampleConfig('layali-envelope');
        $config['opening'] = ['id' => 'envelope'];
        $config['theme']['id'] = 'olive-sand';
        unset($config['media']);

        return $config;
    }

    public static function stripeSignature(string $payload, ?int $time = null, string $secret = self::STRIPE_WEBHOOK_SECRET): string
    {
        $time ??= time();

        return "t={$time},v1=".hash_hmac('sha256', "{$time}.{$payload}", $secret);
    }

    /**
     * A checkout.session.completed event for an order, as Stripe sends it.
     */
    public static function stripeEvent(Order $order, array $session = [], string $type = 'checkout.session.completed'): array
    {
        return [
            'id' => 'evt_'.substr(md5($order->code.$type.json_encode($session)), 0, 16),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $session + [
                'id' => 'cs_test_'.$order->code,
                'object' => 'checkout.session',
                'mode' => 'payment',
                'payment_status' => 'paid',
                'amount_total' => $order->total_cents,
                'currency' => strtolower($order->currency),
                'client_reference_id' => $order->code,
                'metadata' => ['order_code' => $order->code, 'order_id' => $order->id],
                'payment_intent' => 'pi_test_'.$order->code,
            ]],
        ];
    }

    public static function lsSignature(string $payload, string $secret = self::LS_SIGNING_SECRET): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * A Lemon Squeezy order webhook for an order.
     */
    public static function lsEvent(Order $order, string $event = 'order_created', array $attributes = []): array
    {
        return [
            'meta' => [
                'event_name' => $event,
                'custom_data' => ['order_code' => $order->code, 'order_id' => $order->id],
            ],
            'data' => [
                'type' => 'orders',
                'id' => '1'.substr((string) crc32($order->code), 0, 6),
                'attributes' => $attributes + [
                    'store_id' => 12345,
                    'customer_id' => 555,
                    'status' => 'paid',
                    'currency' => 'USD',
                    'total' => $order->total_cents,
                    'refunded' => false,
                    'created_at' => now()->toIso8601String(),
                ],
            ],
        ];
    }
}
