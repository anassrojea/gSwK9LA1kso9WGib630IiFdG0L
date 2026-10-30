<?php

// The door app and door PIN links (phase 7A, plan sections 9 and 14.8).
return [
    'title' => 'Door',
    'by_pass' => 'Door link “:label”',
    'not_found' => 'This code is not an invitation to this event.',

    'locked' => [
        'title' => 'Scanning at the door is part of the QR check-in add-on',
        'text' => 'Scan guests\' codes at the entrance, see arrivals live per section and catch duplicate entries. You can still mark guests as arrived from the dashboard.',
        'cta' => 'Add QR check-in',
        'ask' => 'Ask the hosts to add QR check-in to use the door app.',
    ],

    'live' => ['live' => 'Live', 'polling' => 'Updates every :seconds s', 'offline' => 'Offline'],

    'counters' => [
        'arrived' => 'Arrived',
        'of_expected' => 'of :count expected',
        'capacity' => 'Hall holds :count',
        'families' => ['one' => '1 family', 'other' => ':count families'],
        'sections' => ['men' => 'Men', 'women' => 'Women', 'none' => 'Others'],
    ],

    'scan' => [
        'start' => 'Scan a code',
        'stop' => 'Stop the camera',
        'hint' => 'Point the camera at the guest\'s QR code.',
        'denied' => 'The camera is blocked. Allow it in the browser settings, or search by name.',
        'unsupported' => 'This browser cannot open the camera. Search by name instead.',
        'loading' => 'Opening the camera…',
        'unknown' => 'This code is not an invitation to this event.',
    ],

    'search' => [
        'label' => 'Search by name',
        'placeholder' => 'Family name…',
        'none' => 'No family with that name.',
        'in' => 'Inside',
    ],

    'guest' => [
        'seats' => 'Seats',
        'attending' => 'Coming',
        'kids' => 'Kids',
        'table' => 'Table',
        'section' => 'Section',
        'inside' => 'Inside',
        'people_in' => 'People entering now',
        'status' => ['attending' => 'Confirmed', 'declined' => 'Declined', 'no_reply' => 'No reply'],
        'check_in' => ['one' => 'Check in 1 person', 'other' => 'Check in :count people'],
        'confirm' => ['one' => 'Let 1 person in anyway', 'other' => 'Let :count people in anyway'],
        'cancel' => 'Back',
        'less' => 'One less',
        'more' => 'One more',
    ],

    'warnings' => [
        'already_in' => 'Already inside: :count since :time (:by).',
        'already_in_short' => 'Already inside: :count since :time.',
        'over_limit' => 'Over the limit: :total people for :allowed seats.',
        'not_attending_declined' => 'This family declined the invitation.',
        'not_attending_no_reply' => 'This family never replied.',
        'partial' => 'Only :total of the :expected people they confirmed.',
        'extra' => ':total people, but they confirmed :expected.',
    ],

    'result' => [
        'welcome' => 'Welcome!',
        'checked_in' => ':name: :count in',
        'table' => 'Table :n',
        'section' => ':section section',
        'already_recorded' => 'This scan was already recorded.',
        'offline' => 'Saved on this phone. It is sent as soon as the network is back.',
        'next' => 'Next guest',
    ],

    'queue' => [
        'waiting' => ['one' => '1 scan waiting for the network', 'other' => ':count scans waiting for the network'],
        'sync' => 'Send now',
        'synced' => ['one' => '1 offline scan sent', 'other' => ':count offline scans sent'],
        'conflicts' => 'Needs a second look',
        'checked_in_elsewhere' => 'Was already checked in on another phone meanwhile.',
        'needs_confirmation' => 'Needs a confirmation.',
        'invalid' => 'Unknown code.',
        'let_in' => 'Let in anyway',
        'dismiss' => 'Dismiss',
    ],

    'pin' => [
        'title' => 'Door access',
        'text' => 'Type the 6-digit PIN the hosts gave you.',
        'label' => 'PIN',
        'submit' => 'Open the door app',
        'inactive' => 'This door link no longer works. Ask the hosts for a new one.',
    ],

    'pass' => [
        'title' => 'Door links for staff',
        'hint' => 'For door staff without an account: a link plus a 6-digit PIN, valid until the end of the event day. Send the PIN separately from the link.',
        'label' => 'Who is it for?',
        'label_placeholder' => 'e.g. Main entrance, Ahmad',
        'create' => 'Create a door link',
        'created' => 'Door link ready',
        'pin_once' => 'The PIN is shown only now. Write it down or send it separately.',
        'copy_link' => 'Copy the link',
        'copied' => 'Copied',
        'share' => 'Send on WhatsApp',
        'share_text' => 'Door app for :event: :url',
        'revoke' => 'Revoke',
        'revoked' => 'Revoked',
        'locked_out' => 'Locked (too many wrong PINs)',
        'expired' => 'Expired',
        'active' => 'Active until :time',
        'uses' => ['zero' => 'No scans yet', 'one' => '1 scan', 'other' => ':count scans'],
        'none' => 'No door links yet.',
        'done' => 'Done',
        'event_over' => 'The event is over: door links can no longer be created.',
        'too_many' => 'This event already has the most door links allowed. Revoke one first.',
        'inactive' => 'This door link no longer works. Ask the hosts for a new one.',
        'wrong_pin' => 'Wrong PIN.',
        'slow_down' => 'Too many tries. Wait :seconds seconds.',
        'locked' => 'Too many wrong PINs: this door link is now locked. Ask the hosts for a new one.',
    ],
];
