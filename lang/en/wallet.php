<?php

// Wallet passes (phase 7B, plan section 10)
return [
    // field labels on Apple and Google passes and the web pass
    'pass' => [
        'guest' => 'Guest',
        'date' => 'Date',
        'time' => 'Time',
        'hall' => 'Hall',
        'table' => 'Table',
        'seats' => 'Seats',
        'event' => 'Event',
        'address' => 'Address',
        'link' => 'Your invitation',
        'note' => 'Good to know',
        'note_text' => 'Show this pass at the door. It is personal to your family.',
        'show_at_door' => 'Show at the door',
        'relevant' => 'You are near :hall. Your pass is ready.',
    ],
    // the web pass: /i/{token}/pass
    'web' => [
        'title' => 'Your pass',
        'add_home' => 'Add to home screen',
        'add_home_ios' => 'On iPhone: tap Share, then "Add to Home Screen".',
        'add_home_android' => 'On Android: open the browser menu, then "Add to Home screen".',
        'back' => 'Back to the invitation',
    ],
];
