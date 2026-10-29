<?php

namespace App\Providers;

use App\Services\Entitlements;
use App\Services\InvitationConfig\InvitationConfigValidator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InvitationConfigValidator::class);
        // per request (and per Octane request later): it caches granted features
        $this->app->scoped(Entitlements::class);
    }

    public function boot(): void
    {
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        $this->configureRateLimiting();
    }

    /**
     * Guest routes are public and token-based, so every one is rate-limited
     * (plan sections 15.1 and 20). Keys never include personal data.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('invite-page', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        RateLimiter::for('invite-state', fn (Request $request) => Limit::perMinute(60)
            ->by($request->route('token').'|'.$request->ip()));

        RateLimiter::for('invite-rsvp', fn (Request $request) => [
            Limit::perMinute(10)->by('m|'.$request->route('token').'|'.$request->ip()),
            Limit::perHour(60)->by('h|'.$request->route('token')),
        ]);

        RateLimiter::for('invite-rose', fn (Request $request) => Limit::perMinute(20)
            ->by($request->route('slug').'|'.$request->ip()));
    }
}
