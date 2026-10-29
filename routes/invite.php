<?php

use App\Http\Controllers\Invite\InvitationController;
use App\Http\Controllers\Invite\OgImageController;
use App\Http\Controllers\Invite\RoseController;
use App\Http\Controllers\Invite\RsvpController;
use App\Http\Controllers\Wizard\PreviewController;
use Illuminate\Support\Facades\Route;

/*
| Public guest routes (plan section 18), loaded in the "invite" middleware
| group: no session, cookies or CSRF. The household token is the credential;
| an event's open-link token is served at the same URLs (OpenLinkController).
*/

Route::pattern('token', '[A-Za-z0-9]{10,32}');
Route::pattern('slug', '[a-z0-9-]{3,80}');

Route::get('/i/{token}', [InvitationController::class, 'show'])
    ->middleware('throttle:invite-page')
    ->name('invite.show');

// Link preview image (WhatsApp, Telegram…): never counted as an open
Route::get('/i/{token}/og.png', [OgImageController::class, 'show'])
    ->middleware('throttle:invite-og')
    ->name('invite.og');

Route::get('/i/{token}/state', [InvitationController::class, 'state'])
    ->middleware('throttle:invite-state')
    ->name('invite.state');

Route::post('/i/{token}/rsvp', [RsvpController::class, 'store'])
    ->middleware('throttle:invite-rsvp')
    ->name('invite.rsvp');

Route::post('/e/{slug}/rose', [RoseController::class, 'store'])
    ->middleware('throttle:invite-rose')
    ->name('invite.rose');

// The wizard preview's per-guest calls (placeholder guest, RSVP switched off),
// signed in the path because invite.js sends no cookies and appends ?open=1.
Route::get('/p/{event}/{expires}/{signature}/state', [PreviewController::class, 'state'])
    ->whereUuid('event')->whereNumber('expires')->where('signature', '[0-9a-f]{64}')
    ->middleware('throttle:invite-state')
    ->name('create.preview.state');

Route::post('/p/{event}/{expires}/{signature}/rsvp', [PreviewController::class, 'rsvp'])
    ->whereUuid('event')->whereNumber('expires')->where('signature', '[0-9a-f]{64}')
    ->middleware('throttle:invite-rsvp')
    ->name('create.preview.rsvp');
