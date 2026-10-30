<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Organizations\OrganizationResource;
use App\Models\OrgSubscription;
use App\Services\Digits;
use App\Services\Org\OrgSubscriptions;
use App\Services\Payments\Currencies;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Venue & Planner Pro subscriptions (phase 8A): MRR per currency (live
 * monthly amounts, yearly ones divided by 12; SAR, SYP and USD are never
 * added together), active and past-due subscriptions, and churn this month
 * (subscriptions that ended since the 1st, over those live at the start).
 */
class SubscriptionsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected ?string $pollingInterval = '120s';

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        // a row of MRR per currency, then active, past due and churn
        return 3;
    }

    /**
     * @return array{ended: int, base: int, rate: float}
     */
    public static function churn(?CarbonImmutable $now = null): array
    {
        $start = ($now ?? CarbonImmutable::now())->startOfMonth();
        $ended = OrgSubscription::query()->whereIn('status', ['canceled', 'expired'])->where('ended_at', '>=', $start)->count();
        // live at the start of the month: started before it and not ended before it
        $base = OrgSubscription::query()
            ->where('created_at', '<', $start)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $start))
            ->count();

        return ['ended' => $ended, 'base' => $base, 'rate' => $base > 0 ? round($ended / $base * 100, 1) : 0.0];
    }

    protected function getStats(): array
    {
        $locale = app()->getLocale();
        $n = fn (int|float|string $value) => Digits::localize((string) $value, $locale);
        $url = OrganizationResource::getUrl('index');

        $stats = [];
        foreach (OrgSubscriptions::mrr() as $currency => $amount) {
            $stats[] = Stat::make(trans('org.admin.widget.mrr', ['currency' => $currency]), Currencies::format($amount, $currency, $locale))
                ->description(trans('org.admin.widget.mrr_hint'))
                ->icon(Heroicon::OutlinedArrowTrendingUp)
                ->extraAttributes(['data-mrr' => $currency]);
        }

        $active = OrgSubscription::query()->whereIn('status', ['trialing', 'active'])->count();
        $pastDue = OrgSubscription::query()->where('status', 'past_due')->count();
        $churn = static::churn();

        $stats[] = Stat::make(trans('org.admin.widget.active'), $n($active))->icon(Heroicon::OutlinedBuildingOffice2)->url($url);
        $stats[] = Stat::make(trans('org.admin.widget.past_due'), $n($pastDue))
            ->color($pastDue > 0 ? 'warning' : 'gray')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->url($url);
        $stats[] = Stat::make(trans('org.admin.widget.churn'), $n($churn['rate']).'%')
            ->description(trans('org.admin.widget.churn_hint', ['count' => $n($churn['ended'])]))
            ->icon(Heroicon::OutlinedArrowTrendingDown);

        return $stats;
    }
}
