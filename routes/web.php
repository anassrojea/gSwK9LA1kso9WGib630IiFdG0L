<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\PrivacyController;
use App\Http\Controllers\Account\SecurityController;
use App\Http\Controllers\Auth\EmailCodeController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Dashboard\AlbumController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\EventDashboardController;
use App\Http\Controllers\Dashboard\EventDayController;
use App\Http\Controllers\Dashboard\GuestCsvController;
use App\Http\Controllers\Dashboard\HallController;
use App\Http\Controllers\Dashboard\HallLayoutController;
use App\Http\Controllers\Dashboard\HouseholdController as DashboardHouseholdController;
use App\Http\Controllers\Dashboard\InvitationConfigController;
use App\Http\Controllers\Dashboard\ReminderController;
use App\Http\Controllers\Dashboard\SeatingController;
use App\Http\Controllers\Dashboard\SeatingPdfController;
use App\Http\Controllers\Dashboard\UpgradeController;
use App\Http\Controllers\Door\DoorController;
use App\Http\Controllers\Door\DoorPassController;
use App\Http\Controllers\Host\OpenLinkController;
use App\Http\Controllers\Invoices\InvoiceController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Marketing\HomeController;
use App\Http\Controllers\Payments\CheckoutController;
use App\Http\Controllers\Payments\OrderController;
use App\Http\Controllers\Wizard\AiController;
use App\Http\Controllers\Wizard\GuestController;
use App\Http\Controllers\Wizard\MediaController;
use App\Http\Controllers\Wizard\PreviewController;
use App\Http\Controllers\Wizard\WizardController;
use Illuminate\Support\Facades\Route;

// UUID keys: anything else is a 404 before it reaches PostgreSQL's uuid parser.
Route::pattern('event', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
Route::pattern('household', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
// order codes (Order::generateCode), bound by code
Route::pattern('order', 'DW-[0-9]{4,6}');

// Public pages (Blade). Guest invitations live in routes/invite.php.
Route::get('/', HomeController::class)->name('home');
Route::get('/locale/{locale}', LocaleController::class)->name('locale');

// Public site (phase 6A): /en, occasions, pricing, about, legal pages, sitemap.xml, robots.txt, /r/design-yours
Route::group([], base_path('routes/marketing.php'));

// Creation wizard (plan section 3): no account needed until "Review & send".
// "draft" lets in the owner (EventPolicy) or the browser session that created a guest draft.
Route::get('/create', [WizardController::class, 'create'])->name('create');
Route::post('/create', [WizardController::class, 'store'])->middleware('throttle:drafts')->name('create.store');

Route::middleware('draft')->group(function () {
    Route::get('/create/{event}/preview', [PreviewController::class, 'show'])->name('create.preview');
    Route::get('/create/{event}/{step}', [WizardController::class, 'show'])
        ->where('step', implode('|', WizardController::STEPS))
        ->name('create.step');

    Route::patch('/events/{event}/config', [InvitationConfigController::class, 'update'])
        ->middleware('throttle:autosave')
        ->name('events.config.update');
    Route::post('/events/{event}/media', [MediaController::class, 'store'])
        ->middleware('throttle:uploads')
        ->name('events.media.store');

    Route::post('/events/{event}/guests/import', [GuestController::class, 'import'])->name('events.guests.import');
    Route::post('/events/{event}/guests', [GuestController::class, 'store'])->name('events.guests.store');
    Route::patch('/households/{household}', [GuestController::class, 'update'])->name('households.update');
    Route::delete('/households/{household}', [GuestController::class, 'destroy'])->name('households.destroy');
});

// Host and venue area (Inertia + React). Auth routes come from Fortify.
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // publishing needs an account: guests sign up at this step and their drafts follow them
    Route::post('/create/{event}/publish', [WizardController::class, 'publish'])
        ->middleware('can:update,event')
        ->name('create.publish');

    // Open link (one link for everyone with a people cap): JSON for the wizard and the dashboard
    Route::middleware('can:update,event')->group(function () {
        Route::get('/events/{event}/open-link', [OpenLinkController::class, 'show'])->name('events.open-link.show');
        Route::put('/events/{event}/open-link', [OpenLinkController::class, 'update'])
            ->middleware('throttle:60,1')
            ->name('events.open-link.update');
    });

    // Payments (plan section 12): one order per checkout, then the gateway
    Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:20,1')->name('checkout.store');
    Route::get('/orders/{order}/status', [OrderController::class, 'status'])->middleware('throttle:120,1')->name('orders.status');
    Route::post('/orders/{order}/receipt', [OrderController::class, 'receipt'])->middleware('throttle:20,1')->name('orders.receipt');
    Route::post('/orders/{order}/retry', [OrderController::class, 'retry'])->middleware('throttle:20,1')->name('orders.retry');

    Route::post('/halls/generate', [HallController::class, 'generate'])
        ->middleware('throttle:60,1')
        ->name('halls.generate');
});

// Host dashboard per event (plan section 4.1). Every action is authorized in its
// controller through EventPolicy / HouseholdPolicy (door staff: view and check-in).
Route::middleware('auth')->prefix('dashboard')->name('dashboard.')->group(function () {
    Route::pattern('reminder', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');

    Route::get('/events/{event}', [EventDashboardController::class, 'show'])->name('events.show');
    Route::get('/events/{event}/upgrade', [UpgradeController::class, 'show'])->name('events.upgrade');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::patch('/events/{event}/messages', [EventDashboardController::class, 'messages'])->name('events.messages');
    Route::get('/events/{event}/guests.csv', [EventDashboardController::class, 'export'])->name('events.guests.export');
    Route::post('/events/{event}/guests/csv/preview', [GuestCsvController::class, 'preview'])
        ->middleware('throttle:30,1')
        ->name('events.guests.csv.preview');
    Route::post('/events/{event}/guests/csv', [GuestCsvController::class, 'import'])
        ->middleware('throttle:30,1')
        ->name('events.guests.csv.import');

    Route::post('/events/{event}/reminders', [ReminderController::class, 'store'])->name('events.reminders.store');
    Route::post('/reminders/{reminder}/sent', [ReminderController::class, 'sent'])->name('reminders.sent');

    Route::patch('/households/{household}', [DashboardHouseholdController::class, 'update'])->name('households.update');
    Route::delete('/households/{household}', [DashboardHouseholdController::class, 'destroy'])->name('households.destroy');
    Route::post('/households/{household}/checkin', [DashboardHouseholdController::class, 'checkIn'])->name('households.checkin');
});

// Account area and sign-in methods (phase 5A): Google, email login codes, "My account".
Route::middleware('guest')->group(function () {
    Route::post('/login/code', [EmailCodeController::class, 'send'])->middleware('throttle:20,1')->name('login.code.send');
    Route::post('/login/code/verify', [EmailCodeController::class, 'verify'])->middleware('throttle:30,1')->name('login.code.verify');
    Route::post('/login/code/reset', [EmailCodeController::class, 'reset'])->name('login.code.reset');
});
// Google: signs in a guest, or links Google to the signed-in account (Security tab)
Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])->middleware('throttle:20,1')->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->middleware('throttle:20,1')->name('auth.google.callback');

Route::middleware('auth')->prefix('account')->name('account.')->group(function () {
    Route::get('/', [AccountController::class, 'profile'])->name('profile');
    Route::get('/security', [AccountController::class, 'security'])->name('security');
    Route::get('/billing', [AccountController::class, 'billing'])->name('billing');
    Route::get('/privacy', [AccountController::class, 'privacy'])->name('privacy');

    Route::put('/profile', [AccountController::class, 'updateProfile'])->middleware('throttle:20,1')->name('profile.update');
    Route::post('/email-code', [AccountController::class, 'sendEmailCode'])->middleware('throttle:10,1')->name('email-code');

    Route::put('/password', [SecurityController::class, 'password'])->middleware('throttle:10,1')->name('password');
    Route::delete('/google', [SecurityController::class, 'unlinkGoogle'])->name('google.unlink');
    Route::delete('/sessions', [SecurityController::class, 'signOutOthers'])->name('sessions.destroy');
    Route::delete('/sessions/{key}', [SecurityController::class, 'signOut'])->where('key', '[0-9a-f]{32}')->name('sessions.destroy-one');

    Route::get('/export', [PrivacyController::class, 'export'])->middleware('throttle:5,10')->name('export');
    Route::post('/deletion/code', [PrivacyController::class, 'sendCode'])->middleware('throttle:5,10')->name('deletion.code');
    Route::post('/deletion', [PrivacyController::class, 'request'])->middleware('throttle:6,1')->name('deletion.request');
    Route::delete('/deletion', [PrivacyController::class, 'cancel'])->name('deletion.cancel');
});

// Discount codes at checkout, and the admin's signed previews (admins only, read-only).
Route::middleware('auth')->group(function () {
    Route::post('/checkout/discount', [CheckoutController::class, 'discount'])->middleware('throttle:30,1')->name('checkout.discount');

    Route::middleware('signed')->prefix('admin-preview')->name('admin.preview.')->group(function () {
        Route::get('/events/{event}', [App\Http\Controllers\Admin\PreviewController::class, 'event'])->name('event');
        Route::get('/templates/{template}', [App\Http\Controllers\Admin\PreviewController::class, 'template'])
            ->where('template', '[a-z0-9-]{1,64}')
            ->name('template');
    });
});

// Invoices, receipts and credit notes (phase 6B): the PDF for the customer (their own) and admins.
Route::middleware('auth')->group(function () {
    Route::get('/invoices/{invoice}.pdf', [InvoiceController::class, 'download'])
        ->where('invoice', '[A-Z0-9]{1,12}-[0-9]{4}-[0-9]{1,10}')
        ->middleware('throttle:30,1')
        ->name('invoices.download');
});

// Event-day tools (phase 7A): seating planner, printables, venue halls, the
// door app and its PIN links. Each action is authorized in its controller.
Route::pattern('hall', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
Route::pattern('pass', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard/events/{event}/seating', [SeatingController::class, 'show'])->name('dashboard.events.seating');
    Route::put('/events/{event}/hall', [SeatingController::class, 'hall'])->middleware('throttle:60,1')->name('events.hall.update');
    Route::put('/events/{event}/seats', [SeatingController::class, 'seats'])->middleware('throttle:autosave')->name('events.seats.update');
    Route::post('/events/{event}/seating/auto', [SeatingController::class, 'auto'])->middleware('throttle:30,1')->name('events.seating.auto');
    Route::patch('/events/{event}/seating/section', [SeatingController::class, 'section'])->middleware('throttle:autosave')->name('events.seating.section');

    Route::middleware('throttle:10,1')->group(function () {
        Route::get('/events/{event}/seating.pdf', [SeatingPdfController::class, 'chart'])->name('events.seating.pdf');
        Route::get('/events/{event}/table-cards.pdf', [SeatingPdfController::class, 'cards'])->name('events.table-cards.pdf');
        Route::get('/events/{event}/qr-codes.pdf', [SeatingPdfController::class, 'qrCodes'])->name('events.qr-codes.pdf');
    });

    Route::get('/dashboard/events/{event}/day', [EventDayController::class, 'show'])->name('dashboard.events.day');
    Route::put('/dashboard/events/{event}/live-stream', [EventDayController::class, 'liveStream'])->middleware('throttle:30,1')->name('dashboard.events.live-stream');
    Route::post('/dashboard/events/{event}/door-passes', [EventDayController::class, 'storePass'])->middleware('throttle:20,1')->name('dashboard.events.door-passes.store');
    Route::delete('/dashboard/events/{event}/door-passes/{pass}', [EventDayController::class, 'revokePass'])->name('dashboard.events.door-passes.destroy');

    Route::get('/dashboard/halls', [HallLayoutController::class, 'index'])->name('dashboard.halls.index');
    Route::get('/dashboard/halls/create', [HallLayoutController::class, 'create'])->name('dashboard.halls.create');
    Route::post('/dashboard/halls', [HallLayoutController::class, 'store'])->middleware('throttle:30,1')->name('dashboard.halls.store');
    Route::get('/dashboard/halls/{hall}', [HallLayoutController::class, 'edit'])->name('dashboard.halls.edit');
    Route::put('/dashboard/halls/{hall}', [HallLayoutController::class, 'update'])->middleware('throttle:60,1')->name('dashboard.halls.update');
    Route::delete('/dashboard/halls/{hall}', [HallLayoutController::class, 'destroy'])->name('dashboard.halls.destroy');
});

// Door app (plan sections 9 and 14.8): signed-in staff (EventPolicy::checkIn) or a door PIN link.
Route::prefix('door/{event}')->name('door.')->group(function () {
    Route::get('/', [DoorController::class, 'show'])->name('show');
    Route::get('/guests', [DoorController::class, 'guests'])->middleware('throttle:60,1')->name('guests');
    Route::get('/stats', [DoorController::class, 'stats'])->middleware('throttle:120,1')->name('stats');
    Route::post('/checkin', [DoorController::class, 'checkin'])->middleware('throttle:300,1')->name('checkin');
    Route::post('/sync', [DoorController::class, 'sync'])->middleware('throttle:30,1')->name('sync');

    Route::get('/pass/{pass}', [DoorPassController::class, 'show'])->middleware(['signed', 'throttle:30,1'])->name('pass');
    Route::post('/pass/{pass}', [DoorPassController::class, 'unlock'])->middleware(['signed', 'throttle:20,1'])->name('pass.unlock');
});

// Phase 7B, guest extras: the host's album page, moderation and ZIP export (plan section 11),
// and AI writing in the wizard and the dashboard (plan section 14.3). Authorized in the controllers.
Route::middleware('auth')->group(function () {
    Route::pattern('media', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
    Route::pattern('entry', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
    Route::pattern('generation', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');

    Route::get('/dashboard/events/{event}/album', [AlbumController::class, 'show'])->name('dashboard.events.album');
    Route::put('/dashboard/events/{event}/album/settings', [AlbumController::class, 'settings'])
        ->middleware('throttle:30,1')
        ->name('dashboard.events.album.settings');
    Route::patch('/media/{media}', [AlbumController::class, 'update'])->middleware('throttle:240,1')->name('media.update');
    Route::delete('/media/{media}', [AlbumController::class, 'destroy'])->middleware('throttle:240,1')->name('media.destroy');
    Route::post('/media/{media}/use', [AlbumController::class, 'use'])->middleware('throttle:30,1')->name('media.use');
    Route::delete('/guestbook/{entry}', [AlbumController::class, 'destroyGuestbook'])->name('guestbook.destroy');
    Route::post('/events/{event}/album/export', [AlbumController::class, 'export'])->middleware('throttle:5,10')->name('events.album.export');

    Route::post('/events/{event}/ai/story', [AiController::class, 'story'])->middleware('throttle:ai')->name('events.ai.story');
    Route::post('/events/{event}/ai/poem', [AiController::class, 'poem'])->middleware('throttle:ai')->name('events.ai.poem');
    Route::post('/events/{event}/guests/clean', [AiController::class, 'clean'])->middleware('throttle:ai')->name('events.guests.clean');
    Route::get('/ai/generations/{generation}', [AiController::class, 'show'])->middleware('throttle:ai-poll')->name('ai.generations.show');
});

// the album ZIP from the email: the signature is the key (the link expires)
Route::get('/album-exports/{event}/{file}', [AlbumController::class, 'download'])
    ->where('file', 'album-[0-9]{8}-[0-9]{6}-[a-z0-9]{6}\.zip')
    ->middleware('signed')
    ->name('album.export.download');

// Venue & Planner Pro (phase 8A): /org/*, team and client invitation links
Route::group([], base_path('routes/org.php'));

// Phase 8C: template marketplace and vendor listings (public pages, designer and vendor portals, purchases)
Route::group([], base_path('routes/marketplace.php'));
