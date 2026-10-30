<?php

/*
| The public site (landing, occasions, pricing, about, legal): resources/views/marketing.
*/

$list = fn (string $key) => array_values(array_filter(array_map('trim', explode(',', (string) env($key, '')))));

return [

    /*
    | Sample invitations on the landing page in production: household or open-link tokens
    | of real, published invitations made for the showcase (comma separated). Outside
    | production the demo invitations (DemoSeeder::TOKENS) are shown instead.
    */
    'showcase_tokens' => $list('MARKETING_SHOWCASE_TOKENS'),

    // contact on the About page and in the legal texts; WhatsApp reuses PAYMENTS_WHATSAPP_NUMBER
    'contact_email' => env('MARKETING_CONTACT_EMAIL'),

    // social profiles for the Organization JSON-LD (sameAs), comma separated URLs
    'social' => $list('MARKETING_SOCIAL_URLS'),

    // the date shown on the legal pages; bump it when the texts change
    'legal_updated' => env('LEGAL_UPDATED_AT', '2026-11-03'),

    // occasion slug in the URL => Event::OCCASIONS value
    'occasions' => [
        'wedding' => 'wedding',
        'engagement' => 'engagement',
        'henna' => 'henna',
        'birthday' => 'birthday',
        'graduation' => 'graduation',
        'baby-welcome' => 'baby_welcome',
        'iftar' => 'iftar',
        // phase 8D: occasions with their own templates
        'katb-kitab' => 'katb_kitab',
        'eid' => 'eid',
        'housewarming' => 'housewarming',
        'hajj-return' => 'hajj_return',
        'welcome-home' => 'welcome_home',
        'corporate' => 'corporate',
    ],

    // opening id => demo token whose first screen is the poster (scripts/opening-posters.mjs)
    'opening_demos' => [
        'envelope' => 'LayaliDemo01',
        'rose' => 'WardaDemo001',
        'ringbox' => 'KhutubaDemo1',
        'doors' => 'HennaDemo001',
        'gift' => 'BirthdayDemo',
        'diploma' => 'GradDemo0001',
    ],

];
