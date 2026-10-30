<?php

use App\Services\Payments\Gateways\LemonSqueezyGateway;
use App\Services\Payments\Gateways\StripeGateway;
use App\Services\Payments\Gateways\TransferGateway;

/*
| Payments (plan section 12). Every method sits behind App\Services\Payments\PaymentGateway;
| the checkout only offers the gateways whose enabled() is true. See docs/DEPLOY.md, "Payments".
*/

$account = fn (string $currency) => [
    'account_name' => env("PAYMENTS_BANK_{$currency}_ACCOUNT_NAME"),
    'bank' => env("PAYMENTS_BANK_{$currency}_BANK"),
    'iban' => env("PAYMENTS_BANK_{$currency}_IBAN"),
    'account_number' => env("PAYMENTS_BANK_{$currency}_ACCOUNT_NUMBER"),
    'swift' => env("PAYMENTS_BANK_{$currency}_SWIFT"),
    // free text shown under the details (e.g. a local transfer company in Syria)
    'note' => env("PAYMENTS_BANK_{$currency}_NOTE"),
];

return [

    /*
    | Price lists. Amounts are stored in minor units of each currency's exponent here
    | (not always ISO 4217's): the Syrian pound has no usable subunit, so SYP amounts are whole pounds.
    */
    'currencies' => [
        'SYP' => ['exponent' => 0],
        'SAR' => ['exponent' => 2],
        'USD' => ['exponent' => 2],
    ],

    // the second price list, and what a card method charges when it can't take the host's currency
    'fallback_currency' => 'USD',

    // a host's country picks the default currency (switchable at checkout)
    'countries' => [
        'SY' => 'SYP',
        'SA' => 'SAR',
    ],

    // event time zones that suggest a country before the host picks one
    'timezones' => [
        'Asia/Damascus' => 'SY',
        'Asia/Riyadh' => 'SA',
    ],

    /*
    | Gateways, in the order the checkout lists them. Adding a local gateway (Tap, Moyasar,
    | HyperPay, Paymob) is one class implementing PaymentGateway plus a line here.
    */
    'gateways' => [
        'transfer' => TransferGateway::class,
        'stripe' => StripeGateway::class,
        'lemon_squeezy' => LemonSqueezyGateway::class,
    ],

    // Bank transfer with the receipt sent over WhatsApp, approved by an admin (plan section 12.2)
    'transfer' => [
        'enabled' => (bool) env('PAYMENTS_TRANSFER_ENABLED', true),
        // a transfer order waits this long for the receipt, then expires
        'expires_after_hours' => (int) env('PAYMENTS_TRANSFER_EXPIRES_HOURS', 48),
        // one account per currency: the currency is offered when an IBAN or account number is set
        'accounts' => [
            'SYP' => $account('SYP'),
            'SAR' => $account('SAR'),
            'USD' => $account('USD'),
        ],
    ],

    // international format without "+" or spaces, e.g. 963912345678
    'whatsapp_number' => env('PAYMENTS_WHATSAPP_NUMBER'),

    // Stripe through Laravel Cashier (keys in config/cashier.php: STRIPE_KEY, STRIPE_SECRET, STRIPE_WEBHOOK_SECRET)
    'stripe' => [
        'enabled' => (bool) env('PAYMENTS_STRIPE_ENABLED', true),
        // Stripe does not serve Syria: SYP orders are charged in USD
        'currencies' => ['SAR', 'USD'],
    ],

    // Lemon Squeezy (keys in config/lemon-squeezy.php: LEMON_SQUEEZY_API_KEY, LEMON_SQUEEZY_STORE, LEMON_SQUEEZY_SIGNING_SECRET)
    'lemon_squeezy' => [
        'enabled' => (bool) env('PAYMENTS_LEMON_SQUEEZY_ENABLED', true),
        // a one-time product variant; each checkout overrides its price with custom_price
        'variant_id' => env('LEMON_SQUEEZY_VARIANT_ID'),
        // the store's currency: checkouts are charged in it
        'currencies' => [env('LEMON_SQUEEZY_CURRENCY', 'USD')],
    ],

    /*
    | What each add-on unlocks (plan section 13.2). feature: the entitlements row;
    | days: how long it lasts (null: for good); stackable: can be bought more than once;
    | guests: seats it adds to the event's guest limit; covered_by: a plan feature that already includes it.
    */
    'addons' => [
        'template_unlock' => ['feature' => 'template_unlock', 'days' => null, 'covered_by' => 'premium_templates'],
        'seating' => ['feature' => 'seating', 'days' => null],
        'photo_cloud_90d' => ['feature' => 'photo_cloud', 'days' => 90],
        'photo_cloud_1y' => ['feature' => 'photo_cloud', 'days' => 365],
        'photo_cloud_forever' => ['feature' => 'photo_cloud', 'days' => null],
        'guests_200' => ['feature' => 'guests_200', 'days' => null, 'stackable' => true, 'guests' => 200],
        'qr_checkin' => ['feature' => 'qr_checkin', 'days' => null],
        'wallet' => ['feature' => 'wallet', 'days' => null],
        'video_guestbook' => ['feature' => 'video_guestbook', 'days' => null],
        'thank_you' => ['feature' => 'thank_you', 'days' => null, 'item' => 'thank_you'],
        // AI-written poem (plan section 14.3, App\Http\Controllers\Wizard\AiController::poem)
        'ai_poem' => ['feature' => 'ai_poem', 'days' => null],
        /*
        | Phase 8D products. item: the order line type, fulfilled by its handler
        | (App\Services\Payments\Items, registered in JourneyServiceProvider) instead of
        | the add-on code; hidden: sold from its own page, not listed in the general checkout.
        */
        'save_the_date' => ['feature' => 'save_the_date', 'days' => null, 'item' => 'save_the_date', 'hidden' => true],
        'highlight_video' => ['feature' => 'highlight_video', 'days' => null, 'item' => 'highlight_video'],
        // quantity = printed copies
        'photo_book_20' => ['feature' => 'photo_book', 'days' => null, 'item' => 'photo_book', 'stackable' => true, 'hidden' => true],
        'photo_book_40' => ['feature' => 'photo_book', 'days' => null, 'item' => 'photo_book', 'stackable' => true, 'hidden' => true],
    ],

    // most of one stackable add-on in one order
    'max_quantity' => 10,

];
