<?php

/*
| The venue directory (phase 8B, plan sections 13.1 and 14.8). Labels live in
| lang/{ar,en}/venues.php under cities.*, types.* and amenities.*.
*/
return [
    // filter values for /venues?city=; ISO country for JSON-LD
    'cities' => [
        'riyadh' => 'SA', 'jeddah' => 'SA', 'makkah' => 'SA', 'madinah' => 'SA', 'dammam' => 'SA', 'khobar' => 'SA',
        'abha' => 'SA', 'taif' => 'SA', 'kuwait' => 'KW', 'dubai' => 'AE', 'abu-dhabi' => 'AE', 'sharjah' => 'AE',
        'doha' => 'QA', 'manama' => 'BH', 'muscat' => 'OM', 'amman' => 'JO', 'cairo' => 'EG',
    ],

    'types' => ['hall', 'hotel', 'garden', 'resort', 'restaurant', 'other'],

    'amenities' => [
        'parking', 'valet', 'prayer_room', 'women_section', 'bridal_room', 'kosha', 'catering', 'sound_system',
        'lighting', 'screens', 'kids_area', 'outdoor', 'wifi', 'accessible',
    ],

    'per_page' => 24,

    'max_photos' => 12,

    // kilobytes
    'max_photo_kb' => 8 * 1024,
];
