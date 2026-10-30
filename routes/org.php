<?php

use App\Http\Controllers\Org\BillingController;
use App\Http\Controllers\Org\OrganizationController;
use App\Http\Controllers\Org\OrgEventController;
use App\Http\Controllers\Org\OrgInvitationController;
use App\Http\Controllers\Org\TeamController;
use Illuminate\Support\Facades\Route;

/*
| Venue & Planner Pro (phase 8A): organizations, the venue dashboard, team, client events,
| white label and subscriptions. Loaded from routes/web.php (web middleware). Every action
| is authorized in its controller through OrganizationPolicy / EventPolicy.
*/

Route::pattern('organization', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
Route::pattern('member', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
Route::pattern('invitation', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');

Route::middleware('auth')->prefix('org')->name('org.')->group(function () {
    Route::get('/', [OrganizationController::class, 'index'])->name('index');
    Route::get('/create', [OrganizationController::class, 'create'])->name('create');
    Route::post('/', [OrganizationController::class, 'store'])->middleware('throttle:10,1')->name('store');
    Route::get('/{organization}', [OrganizationController::class, 'show'])->name('show');
    Route::get('/{organization}/settings', [OrganizationController::class, 'settings'])->name('settings');
    Route::post('/{organization}/settings', [OrganizationController::class, 'update'])->middleware('throttle:30,1')->name('update');

    Route::post('/{organization}/events', [OrgEventController::class, 'store'])->middleware('throttle:drafts')->name('events.store');
    Route::get('/{organization}/events/{event}', [OrgEventController::class, 'show'])->name('events.show');
    Route::post('/{organization}/events/{event}/client', [OrgEventController::class, 'inviteClient'])->middleware('throttle:20,1')->name('events.client');

    Route::get('/{organization}/team', [TeamController::class, 'index'])->name('team');
    Route::post('/{organization}/invitations', [TeamController::class, 'invite'])->middleware('throttle:20,1')->name('invitations.store');
    Route::delete('/{organization}/invitations/{invitation}', [TeamController::class, 'revoke'])->name('invitations.destroy');
    Route::patch('/{organization}/members/{member}', [TeamController::class, 'role'])->name('members.update');
    Route::delete('/{organization}/members/{member}', [TeamController::class, 'remove'])->name('members.destroy');
    Route::post('/{organization}/transfer', [TeamController::class, 'transfer'])->middleware('throttle:10,1')->name('transfer');
    Route::post('/{organization}/leave', [TeamController::class, 'leave'])->name('leave');

    Route::get('/{organization}/billing', [BillingController::class, 'show'])->name('billing');
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('/{organization}/billing/subscribe', [BillingController::class, 'subscribe'])->name('billing.subscribe');
        Route::post('/{organization}/billing/change', [BillingController::class, 'change'])->name('billing.change');
        Route::post('/{organization}/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
        Route::post('/{organization}/billing/resume', [BillingController::class, 'resume'])->name('billing.resume');
        Route::post('/{organization}/billing/renew', [BillingController::class, 'renew'])->name('billing.renew');
        Route::get('/{organization}/billing/portal', [BillingController::class, 'portal'])->name('billing.portal');
    });
});

// the link in team and client invitation emails: signed, and the token is checked against its hash
Route::get('/org-invitations/{invitation}/{token}', [OrgInvitationController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{48}')
    ->middleware(['signed', 'throttle:30,1'])
    ->name('org.invitations.show');
Route::post('/org-invitations/{invitation}/{token}', [OrgInvitationController::class, 'accept'])
    ->where('token', '[A-Za-z0-9]{48}')
    ->middleware(['auth', 'signed', 'throttle:20,1'])
    ->name('org.invitations.accept');
