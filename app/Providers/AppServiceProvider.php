<?php

namespace App\Providers;

use App\Models\Household;
use App\Models\Invitation;
use App\Models\OpenLink;
use App\Observers\OgImageWarmer;
use App\Services\DraftAccess;
use App\Services\Entitlements;
use App\Services\InvitationConfig\InvitationConfigValidator;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InvitationConfigValidator::class);
        $this->app->singleton(Entitlements::class);
    }

    public function boot(): void
    {
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        $this->configureRateLimiting();

        // Link preview images are rendered ahead of time when what they show changes.
        Household::observe(OgImageWarmer::class);
        Invitation::observe(OgImageWarmer::class);
        OpenLink::observe(OgImageWarmer::class);

        // Drafts started without an account follow the visitor into their new or existing account.
        Event::listen([Login::class, Registered::class], function (Login|Registered $event) {
            if (request()->hasSession()) {
                app(DraftAccess::class)->attachTo($event->user);
            }
        });
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

        RateLimiter::for('invite-rsvp', function (Request $request) {
            $token = (string) $request->route('token');

            // The open link is shared by everyone, so its hourly budget is per device
            // (same numbers as a personal link) plus a ceiling for the whole link.
            if (strlen($token) === OpenLink::TOKEN_LENGTH) {
                return [
                    Limit::perMinute(10)->by('m|'.$token.'|'.$request->ip()),
                    Limit::perHour(60)->by('h|'.$token.'|'.$request->ip()),
                    Limit::perHour(1000)->by('h|'.$token),
                ];
            }

            return [
                Limit::perMinute(10)->by('m|'.$token.'|'.$request->ip()),
                Limit::perHour(60)->by('h|'.$token),
            ];
        });

        // Link preview images: crawlers fetch them once per share; the edge cache absorbs the rest.
        RateLimiter::for('invite-og', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('invite-rose', fn (Request $request) => Limit::perMinute(20)
            ->by($request->route('slug').'|'.$request->ip()));

        // Wizard: anyone can start a draft, so creating them is throttled per visitor
        RateLimiter::for('drafts', fn (Request $request) => [
            Limit::perMinute(10)->by('m|'.($request->user()?->getKey() ?? $request->ip())),
            Limit::perHour(60)->by('h|'.$request->ip()),
        ]);

        RateLimiter::for('autosave', fn (Request $request) => Limit::perMinute(120)
            ->by(($request->user()?->getKey() ?? $request->ip()).'|'.$request->path()));

        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->getKey() ?? $request->ip()));
    }
}
