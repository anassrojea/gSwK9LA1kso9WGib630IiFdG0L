<?php

namespace App\Providers;

use App\Models\Addon;
use App\Models\Event as EventModel;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\OpenLink;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Observers\EdgeCachePurger;
use App\Observers\OgImageWarmer;
use App\Observers\OrderAnalytics;
use App\Services\Account\SessionRegistry;
use App\Services\DraftAccess;
use App\Services\Entitlements;
use App\Services\InvitationConfig\InvitationConfigValidator;
use App\Services\Payments\PaymentGateways;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use LemonSqueezy\Laravel\LemonSqueezy;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InvitationConfigValidator::class);
        $this->app->singleton(Entitlements::class);
        $this->app->singleton(PaymentGateways::class);

        // Payments: our own webhook routes (routes/webhooks.php) and UUID-safe
        // tables (2026_11_01_000200/000300) replace the packages' defaults.
        Cashier::ignoreRoutes();
        LemonSqueezy::ignoreRoutes();
        LemonSqueezy::ignoreMigrations();
    }

    public function boot(): void
    {
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        $this->configureRateLimiting();

        // prices.priceable_type stores short names, not class names
        Relation::morphMap(['plan' => Plan::class, 'addon' => Addon::class]);

        // Link preview images are rendered ahead of time when what they show changes.
        Household::observe(OgImageWarmer::class);
        Invitation::observe(OgImageWarmer::class);
        OpenLink::observe(OgImageWarmer::class);

        // Cached guest pages are purged from Cloudflare when what they show changes (plan section 6.4).
        foreach ([Invitation::class, Household::class, OpenLink::class, EventModel::class, Media::class] as $model) {
            $model::observe(EdgeCachePurger::class);
        }

        // "order_paid" for the analytics funnel (plan section 22); a no-op without POSTHOG_KEY
        Order::observe(OrderAnalytics::class);

        // Laravel Pulse at /pulse: admins only, like Horizon
        Gate::define('viewPulse', fn (?User $user = null) => $user?->isAdmin() ?? false);

        // Drafts started without an account follow the visitor into their new or existing account.
        Event::listen([Login::class, Registered::class], function (Login|Registered $event) {
            if (request()->hasSession()) {
                app(DraftAccess::class)->attachTo($event->user);
            }
        });

        // Account → Security lists signed-in devices; a device that logs out leaves the list.
        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user instanceof User && request()->hasSession()) {
                app(SessionRegistry::class)->forget($event->user, request()->session()->getId());
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

        // payment providers retry on failure; this only stops floods
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));

        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->getKey() ?? $request->ip()));
    }
}
