<?php

use App\Http\Controllers\Invite\AlbumController;
use App\Http\Controllers\Invite\InvitationController;
use App\Http\Controllers\Invite\OgImageController;
use App\Http\Controllers\Invite\QrController;
use App\Http\Controllers\Invite\RoseController;
use App\Http\Controllers\Invite\RsvpController;
use App\Http\Controllers\Invite\WallController;
use App\Http\Controllers\Invite\WalletController;
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

// The household's QR code = its personal link (plan section 9), never counted as an open
Route::get('/i/{token}/qr.svg', [QrController::class, 'svg'])
    ->middleware('throttle:invite-og')
    ->name('invite.qr');

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

/*
| Phase 7B, guest extras (plan sections 10, 11 and 18): the event album and
| live photo wall, wallet passes. {token} is a household's link, the open link
| or the album's own link (App\Services\Album\AlbumAccess).
*/
Route::get('/i/{token}/album', [AlbumController::class, 'show'])->middleware('throttle:album-read')->name('album.show');
Route::get('/i/{token}/album/items', [AlbumController::class, 'items'])->middleware('throttle:album-read')->name('album.items');
Route::post('/i/{token}/album/pin', [AlbumController::class, 'pin'])->middleware('throttle:album-pin')->name('album.pin');
Route::post('/i/{token}/uploads', [AlbumController::class, 'upload'])->middleware('throttle:album-uploads')->name('album.upload');
Route::post('/i/{token}/uploads/complete', [AlbumController::class, 'complete'])->middleware('throttle:album-uploads')->name('album.complete');
Route::post('/i/{token}/guestbook', [AlbumController::class, 'guestbook'])->middleware('throttle:album-guestbook')->name('album.guestbook');
Route::get('/m/{media}/{variant}', [AlbumController::class, 'file'])
    ->whereUuid('media')->whereIn('variant', ['original', 'preview', 'thumb'])
    ->middleware(['signed:relative', 'throttle:album-files'])
    ->name('album.file');

Route::get('/e/{slug}/wall', [WallController::class, 'show'])->middleware('throttle:wall')->name('wall.show');
Route::get('/e/{slug}/wall/feed', [WallController::class, 'feed'])->middleware('throttle:wall')->name('wall.feed');

Route::get('/i/{token}/wallet/{platform}', [WalletController::class, 'show'])
    ->whereIn('platform', ['apple', 'google'])
    ->middleware('throttle:wallet')
    ->name('invite.wallet');
Route::get('/i/{token}/pass', [WalletController::class, 'pass'])->middleware('throttle:wallet')->name('invite.pass');
Route::get('/i/{token}/pass.webmanifest', [WalletController::class, 'manifest'])->middleware('throttle:wallet')->name('invite.pass.manifest');
Route::get('/e/{slug}/wallet-strip.png', [WalletController::class, 'strip'])->middleware('throttle:invite-og')->name('invite.wallet.strip');
