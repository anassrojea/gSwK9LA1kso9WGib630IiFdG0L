<?php

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\EventDashboardController;
use App\Http\Controllers\Dashboard\GuestCsvController;
use App\Http\Controllers\Dashboard\HallController;
use App\Http\Controllers\Dashboard\HouseholdController as DashboardHouseholdController;
use App\Http\Controllers\Dashboard\InvitationConfigController;
use App\Http\Controllers\Dashboard\ReminderController;
use App\Http\Controllers\Dashboard\UpgradeController;
use App\Http\Controllers\Host\OpenLinkController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Marketing\HomeController;
use App\Http\Controllers\Payments\CheckoutController;
use App\Http\Controllers\Payments\OrderController;
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
