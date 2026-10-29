<?php

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\HallController;
use App\Http\Controllers\Dashboard\InvitationConfigController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Marketing\HomeController;
use Illuminate\Support\Facades\Route;

// UUID keys: anything else is a 404 before it reaches PostgreSQL's uuid parser.
Route::pattern('event', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');

// Public pages (Blade). Guest invitations live in routes/invite.php.
Route::get('/', HomeController::class)->name('home');
Route::get('/locale/{locale}', LocaleController::class)->name('locale');

// Host and venue area (Inertia + React). Auth routes come from Fortify.
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::patch('/events/{event}/config', [InvitationConfigController::class, 'update'])->name('events.config.update');

    Route::post('/halls/generate', [HallController::class, 'generate'])
        ->middleware('throttle:60,1')
        ->name('halls.generate');
});
