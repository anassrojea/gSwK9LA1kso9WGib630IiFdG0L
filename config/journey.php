<?php

/*
| The family journey (phase 8D, plan sections 14.2, 14.5 and 14.7): bundles,
| the loyalty discount, referrals and the anniversary email.
| See App\Services\Journey and docs/AFTER_EVENT.md.
*/

return [

    'bundles' => [
        // a purchased bundle's credits can be used for this many days (2 years)
        'validity_days' => (int) env('JOURNEY_BUNDLE_VALIDITY_DAYS', 730),
    ],

    // Returning customers: this percent off the plan of their next event, automatically.
    // A discount code that takes off more wins instead; the two never add up.
    'loyalty' => [
        'enabled' => (bool) env('JOURNEY_LOYALTY_ENABLED', true),
        'percent' => (int) env('JOURNEY_LOYALTY_PERCENT', 10),
    ],

    'referrals' => [
        'enabled' => (bool) env('JOURNEY_REFERRALS_ENABLED', true),
        // the referral cookie set by /r/{code}
        'cookie' => 'daawa_ref',
        'cookie_days' => (int) env('JOURNEY_REFERRAL_COOKIE_DAYS', 30),
        // the referrer's credit when the referred user's first paid order is paid or approved,
        // in minor units of the order's currency (PLACEHOLDERS: SAR halalas, SYP whole pounds)
        'reward' => [
            'USD' => (int) env('JOURNEY_REFERRAL_REWARD_USD', 500),
            'SAR' => (int) env('JOURNEY_REFERRAL_REWARD_SAR', 1900),
            'SYP' => (int) env('JOURNEY_REFERRAL_REWARD_SYP', 60000),
        ],
        // the referred user's first order: this percent off its plan (the better of this, loyalty or a code)
        'welcome_percent' => (int) env('JOURNEY_REFERRAL_WELCOME_PERCENT', 10),
        // rewards one referrer can earn per calendar month; later referrals are recorded as "capped"
        'monthly_cap' => (int) env('JOURNEY_REFERRAL_MONTHLY_CAP', 10),
        // a sign-up is blocked as a self-referral on the same email (normalised), the same phone,
        // or the same IP as the referrer together with the same email domain (Referrals::looksLikeSelf)
    ],

    'anniversary' => [
        'enabled' => (bool) env('JOURNEY_ANNIVERSARY_ENABLED', true),
        // a run that was missed still sends within this many days after the date
        'grace_days' => 7,
        // only events that took place (not drafts) and were not deleted
        'statuses' => ['active', 'ended', 'archived'],
    ],

];
