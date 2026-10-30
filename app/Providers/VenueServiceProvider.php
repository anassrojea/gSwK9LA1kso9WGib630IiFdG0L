<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 8B, venues: the hall designer, the venue directory and the venue QR /
 * Smart Hall. Registers the rate limits of the public "find your table" lookup:
 * a few tries per guest, a budget per device per hour and a ceiling for the
 * whole event, so the guest list cannot be walked by guessing names.
 */
class VenueServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('find-table', fn (Request $request) => [
            Limit::perMinute((int) config('venues.find_per_minute', 6))->by('m|'.$request->route('slug').'|'.$request->ip()),
            Limit::perHour((int) config('venues.find_per_hour', 30))->by('h|'.$request->ip()),
            Limit::perHour((int) config('venues.find_per_event_hour', 600))->by('e|'.$request->route('slug')),
        ]);
    }
}
