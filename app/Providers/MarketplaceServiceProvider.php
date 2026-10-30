<?php

namespace App\Providers;

use App\Models\Invitation;
use App\Services\Marketplace\MarketplaceSitemap;
use App\Services\Marketplace\TemplateAccess;
use App\Services\PublishGate;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 8C: the template marketplace and vendor listings (docs/MARKETPLACE.md).
 * Its schedules and sitemap entries are registered here; its order item types are
 * hooked into the payment core through MarketplaceOrders (one line each).
 */
class MarketplaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // sitemap.xml entries beyond the site's own pages (App\Http\Controllers\Marketing\SeoController)
        $this->app->tag([MarketplaceSitemap::class], 'sitemap.entries');
    }

    public function boot(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // the month before, on the 1st (UTC): statements per designer and currency above the minimum
            $schedule->command('marketplace:payout-statements')->monthlyOn(1, '05:41')->withoutOverlapping();
            $schedule->command('vendors:end-features')->hourlyAt(23)->withoutOverlapping();
        });

        // a Signature event publishing an "included in Signature" template: recorded for the designers' pool
        Invitation::saved(function (Invitation $invitation) {
            if ($invitation->published_at && ($invitation->wasRecentlyCreated || $invitation->wasChanged('published_at')) && ($event = $invitation->event)) {
                app(TemplateAccess::class)->recordSignatureUse($event, $invitation->config ?? [], app(PublishGate::class)->currentPlan($event));
            }
        });
    }
}
