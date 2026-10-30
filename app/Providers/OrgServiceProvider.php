<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Org\OrgTeam;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 8A, Venue & Planner Pro: an invitation link opened while signed out
 * is accepted as soon as the visitor logs in or registers (Fortify logs new
 * accounts in, so the Login event covers both).
 */
class OrgServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(Login::class, function (Login $event) {
            if (request()->hasSession() && request()->session()->has(OrgTeam::SESSION_KEY) && $event->user instanceof User) {
                app(OrgTeam::class)->acceptFromSession($event->user);
            }
        });
    }
}
