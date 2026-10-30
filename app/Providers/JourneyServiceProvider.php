<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\User;
use App\Observers\ReferralRewards;
use App\Observers\ReferralSignups;
use App\Services\Payments\Items\BundleCreditItem;
use App\Services\Payments\Items\BundleItem;
use App\Services\Payments\Items\HighlightVideoItem;
use App\Services\Payments\Items\OrderItemHandlers;
use App\Services\Payments\Items\PhotoBookItem;
use App\Services\Payments\Items\PlanReductionItem;
use App\Services\Payments\Items\ReferralCreditItem;
use App\Services\Payments\Items\SaveTheDateItem;
use App\Services\Payments\Items\ThankYouItem;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 8D, after-event products and the family journey: the order line
 * types they sell (registered with OrderItemHandlers, which OrderFulfillment
 * calls), the referral hooks on sign-up and on paid orders, and rate limits.
 *
 * Other features add their own line types the same way, from their own provider:
 *     $this->app->afterResolving(OrderItemHandlers::class, fn ($h) => $h->register('type', Handler::class));
 */
class JourneyServiceProvider extends ServiceProvider
{
    /** Order line type => handler (plan sections 13.2, 14.2, 14.5, 14.7). */
    public const ITEMS = [
        'save_the_date' => SaveTheDateItem::class,
        'thank_you' => ThankYouItem::class,
        'highlight_video' => HighlightVideoItem::class,
        'photo_book' => PhotoBookItem::class,
        'bundle' => BundleItem::class,
        'bundle_credit' => BundleCreditItem::class,
        'referral_credit' => ReferralCreditItem::class,
        'loyalty' => PlanReductionItem::class,
        'referral_welcome' => PlanReductionItem::class,
    ];

    public function register(): void
    {
        $this->app->singleton(OrderItemHandlers::class);

        $this->app->afterResolving(OrderItemHandlers::class, function (OrderItemHandlers $handlers) {
            foreach (self::ITEMS as $type => $handler) {
                if (! $handlers->has($type)) {
                    $handlers->register($type, $handler);
                }
            }
        });
    }

    public function boot(): void
    {
        User::observe(ReferralSignups::class);
        Order::observe(ReferralRewards::class);

        RateLimiter::for('referral', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('keepsakes', fn (Request $request) => Limit::perMinute(30)->by((string) ($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('thank-you-page', fn (Request $request) => Limit::perMinute(120)->by((string) $request->route('token').'|'.$request->ip()));
    }
}
