<?php

/*
| Venue & Planner Pro (plan section 13.1, phase 8A). Tier limits live in the
| plans table (Database\Seeders\OrgPlanSeeder, editable in Filament); this
| file holds the provider ids and the lifecycle timings. See docs/VENUE_PRO.md.
*/

$stripePrices = fn (string $plan) => [
    'USD' => env('STRIPE_PRICE_'.strtoupper($plan).'_USD'),
    'SAR' => env('STRIPE_PRICE_'.strtoupper($plan).'_SAR'),
];

return [

    // the tiers, cheapest first; each has a monthly row and a "{tier}_yearly" row in plans
    'tiers' => ['venue_starter', 'venue_pro', 'venue_business'],

    'yearly_suffix' => '_yearly',

    // an organization without a subscription: the owner and this many more members
    'free_seats' => (int) env('ORG_FREE_SEATS', 2),

    'stripe' => [
        /*
        | Recurring Stripe prices per plan row and currency (STRIPE_PRICE_VENUE_PRO_USD,
        | STRIPE_PRICE_VENUE_PRO_YEARLY_SAR, …). Without one, Checkout sends an inline
        | price_data instead; changing tiers (proration) and the Billing Portal's plan
        | switcher need real prices.
        */
        'prices' => [
            'venue_starter' => $stripePrices('venue_starter'),
            'venue_starter_yearly' => $stripePrices('venue_starter_yearly'),
            'venue_pro' => $stripePrices('venue_pro'),
            'venue_pro_yearly' => $stripePrices('venue_pro_yearly'),
            'venue_business' => $stripePrices('venue_business'),
            'venue_business_yearly' => $stripePrices('venue_business_yearly'),
        ],
        // Billing Portal configuration (bpc_…); Stripe's default when empty
        'portal_configuration' => env('STRIPE_PORTAL_CONFIGURATION'),
    ],

    'lemon_squeezy' => [
        // one subscription product with a variant per plan row (charged in the store currency)
        'product_id' => env('LEMON_SQUEEZY_VENUE_PRODUCT_ID'),
        'variants' => [
            'venue_starter' => env('LEMON_SQUEEZY_VARIANT_VENUE_STARTER'),
            'venue_starter_yearly' => env('LEMON_SQUEEZY_VARIANT_VENUE_STARTER_YEARLY'),
            'venue_pro' => env('LEMON_SQUEEZY_VARIANT_VENUE_PRO'),
            'venue_pro_yearly' => env('LEMON_SQUEEZY_VARIANT_VENUE_PRO_YEARLY'),
            'venue_business' => env('LEMON_SQUEEZY_VARIANT_VENUE_BUSINESS'),
            'venue_business_yearly' => env('LEMON_SQUEEZY_VARIANT_VENUE_BUSINESS_YEARLY'),
        ],
    ],

    'transfer' => [
        // the renewal order is created this many days before current_period_end
        'renew_days_before' => (int) env('ORG_RENEW_DAYS_BEFORE', 7),
        // reminder emails, days before current_period_end
        'reminder_days' => [7, 1],
    ],

    // past_due this long (any provider), then expired
    'grace_days' => (int) env('ORG_GRACE_DAYS', 7),

    // team and client invitation links
    'invitation_days' => 7,

    // logo upload (public disk)
    'logo_max_kb' => 1024,

];
