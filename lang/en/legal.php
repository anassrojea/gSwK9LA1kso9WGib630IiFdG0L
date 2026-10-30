<?php

// Legal pages. The texts live in resources/content/legal/en/*.md (drafts: review with a lawyer).
return [
    'pages' => [
        'terms' => ['title' => 'Terms of service', 'description' => 'Terms for using Daawa: your account, invitation content, plans, card payments and bank transfers.'],
        'privacy' => ['title' => 'Privacy policy', 'description' => 'What data Daawa collects about hosts and guests, why, how long it is kept, and how to delete it.'],
        'refund' => ['title' => 'Refund policy', 'description' => 'When you can get a refund for a Daawa plan or add-on, how to ask, and how it is paid back.'],
        'cookies' => ['title' => 'Cookie notice', 'description' => 'Cookies and measurement tools on the Daawa website, and how to change your choice.'],
    ],
    'updated' => 'Last updated: :date',
    'other' => 'Other pages',
    'contact_email' => ' or by email at :email',
    'retention' => [
        'none' => 'the :plan plan has no photo album',
        'days' => ':plan keeps it :days days after the event',
        'joiner' => '; ',
    ],
    'consent_button' => 'Change my cookie choice',
];
