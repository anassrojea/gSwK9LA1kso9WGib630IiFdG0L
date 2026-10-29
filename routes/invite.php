<?php

use App\Http\Controllers\Invite\InvitationController;
use App\Http\Controllers\Invite\RoseController;
use App\Http\Controllers\Invite\RsvpController;
use Illuminate\Support\Facades\Route;

/*
| Public guest routes (plan section 18), loaded in the "invite" middleware
| group: no session, cookies or CSRF. The household token is the credential.
*/

Route::pattern('token', '[A-Za-z0-9]{10,32}');
Route::pattern('slug', '[a-z0-9-]{3,80}');

Route::get('/i/{token}', [InvitationController::class, 'show'])
    ->middleware('throttle:invite-page')
    ->name('invite.show');

Route::get('/i/{token}/state', [InvitationController::class, 'state'])
    ->middleware('throttle:invite-state')
    ->name('invite.state');

Route::post('/i/{token}/rsvp', [RsvpController::class, 'store'])
    ->middleware('throttle:invite-rsvp')
    ->name('invite.rsvp');

Route::post('/e/{slug}/rose', [RoseController::class, 'store'])
    ->middleware('throttle:invite-rose')
    ->name('invite.rose');
