<?php

/*
| Phase 8C: the template marketplace and vendor listings (plan sections 5, 13.2, 14.6).
| Money is in minor units of each currency's exponent (config/payments.php): SYP whole pounds,
| SAR and USD cents. See docs/MARKETPLACE.md.
*/

return [

    /*
    | Designers (plan section 13.2: "designers 70%, you 30%"). The share is in basis points
    | (7000 = 70 %) of the net line amount: after the order's discount, without VAT.
    | Each designer can get their own rate in Filament (designer_profiles.commission_bp).
    */
    'designer_share_bp' => (int) env('MARKETPLACE_DESIGNER_SHARE_BP', 7000),

    // version of resources/content/legal/{ar,en}/designers.md the designer accepted
    'terms_version' => '2026-11-draft',

    /*
    | What a designer may charge for one template, per currency (min, max), minor units.
    | USD is required: card gateways charge USD when they can't take the host's currency.
    */
    'price_bounds' => [
        'USD' => [300, 10000],
        'SAR' => [1000, 40000],
        'SYP' => [20000, 5000000],
    ],
    'required_price_currencies' => ['USD'],

    // monthly payout statements (marketplace:payout-statements): only from this balance on, per currency
    'payout_minimum' => [
        'USD' => (int) env('MARKETPLACE_PAYOUT_MIN_USD', 5000),
        'SAR' => (int) env('MARKETPLACE_PAYOUT_MIN_SAR', 20000),
        'SYP' => (int) env('MARKETPLACE_PAYOUT_MIN_SYP', 500000),
    ],

    // templates in the marketplace sort after the house catalog in the wizard
    'template_sort' => 1000,

    /*
    | Vendor listings (plan section 5: "pay for featured spots").
    */
    'vendor_categories' => ['photographer', 'kosha', 'band', 'cake', 'dress', 'makeup', 'calligraphy'],

    // cities a listing can serve (names in lang/{ar,en}/vendors.php)
    'cities' => [
        'damascus' => 'SY', 'aleppo' => 'SY', 'homs' => 'SY', 'latakia' => 'SY', 'hama' => 'SY', 'tartus' => 'SY',
        'riyadh' => 'SA', 'jeddah' => 'SA', 'mecca' => 'SA', 'medina' => 'SA', 'dammam' => 'SA', 'khobar' => 'SA',
    ],

    // "featured in {city}/{category} for 30 days", sold as the order item vendor_feature
    'feature' => [
        'days' => 30,
        'prices' => [
            'USD' => (int) env('MARKETPLACE_FEATURE_USD', 2900),
            'SAR' => (int) env('MARKETPLACE_FEATURE_SAR', 10900),
            'SYP' => (int) env('MARKETPLACE_FEATURE_SYP', 400000),
        ],
    ],

    // calligraphy name logo (plan section 14.6: $15–30), priced by the calligrapher within these bounds
    'calligraphy_bounds' => [
        'USD' => [1500, 3000],
        'SAR' => [5500, 11500],
        'SYP' => [100000, 500000],
    ],

    'max_photos' => 8,
    'max_upload_kb' => 4096,

];
