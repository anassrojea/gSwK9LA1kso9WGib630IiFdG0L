<?php

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\HallController;
use App\Http\Controllers\Dashboard\InvitationConfigController;
use App\Http\Controllers\Host\OpenLinkController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Marketing\HomeController;
use App\Http\Controllers\Wizard\GuestController;
use App\Http\Controllers\Wizard\MediaController;
use App\Http\Controllers\Wizard\PreviewController;
use App\Http\Controllers\Wizard\WizardController;
use Illuminate\Support\Facades\Route;

// UUID keys: anything else is a 404 before it reaches PostgreSQL's uuid parser.
Route::pattern('event', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
Route::pattern('household', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');

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

    Route::post('/halls/generate', [HallController::class, 'generate'])
        ->middleware('throttle:60,1')
        ->name('halls.generate');
});
