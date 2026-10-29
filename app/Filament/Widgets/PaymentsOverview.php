<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use App\Services\Digits;
use App\Services\Payments\Currencies;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The admin's "Today" (plan section 4.3): revenue per currency (today and
 * this month: SAR, SYP and USD are never added together), new events,
 * published events, transfers waiting for approval and new customers.
 * Revenue is money kept: paid and approved orders, by the time they were
 * paid; a refunded order drops out.
 */
class PaymentsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        // one row of money (a card per currency, then the transfer queue), one row of activity
        return count(Currencies::all()) + 1;
    }

    /**
     * Revenue in minor units per currency since $from, and the number of orders.
     *
     * @return array<string, array{total: int, orders: int}>
     */
    public static function revenue(CarbonImmutable $from): array
    {
        $rows = Order::query()
            ->fulfilled()
            ->where('paid_at', '>=', $from)
            ->groupBy('currency')
            ->selectRaw('currency, sum(total_cents) as total, count(*) as orders')
            ->get()
            ->keyBy('currency');

        return collect(Currencies::all())
            ->mapWithKeys(fn (string $c) => [$c => ['total' => (int) ($rows[$c]->total ?? 0), 'orders' => (int) ($rows[$c]->orders ?? 0)]])
            ->all();
    }

    protected function getStats(): array
    {
        $locale = app()->getLocale();
        $n = fn (int $value) => Digits::localize($value, $locale);
        $today = CarbonImmutable::now()->startOfDay();
        $month = CarbonImmutable::now()->startOfMonth();

        $daily = static::revenue($today);
        $monthly = static::revenue($month);

        $stats = [];
        foreach (Currencies::all() as $currency) {
            $stats[] = Stat::make(trans('app.admin.widgets.revenue', ['currency' => $currency]), Currencies::format($daily[$currency]['total'], $currency, $locale))
                // today's order count first, so it isn't read as the month's
                ->description(trans_choice('app.admin.widgets.orders', $daily[$currency]['orders'], ['count' => $n($daily[$currency]['orders'])])
                    .' · '.trans('admin.dashboard.month', ['amount' => Currencies::format($monthly[$currency]['total'], $currency, $locale)]))
                ->icon(Heroicon::OutlinedBanknotes)
                ->extraAttributes(['data-revenue' => $currency]);
        }

        $waiting = Order::query()->where('status', 'receipt_received')->count();
        $newEvents = Event::query()->where('created_at', '>=', $today)->count();
        $published = Invitation::query()->whereNotNull('published_at')->count();
        $publishedToday = Invitation::query()->where('published_at', '>=', $today)->count();
        $newUsers = User::query()->where('created_at', '>=', $today)->count();
        $newUsersMonth = User::query()->where('created_at', '>=', $month)->count();

        $stats[] = Stat::make(trans('app.admin.orders.waiting'), $n($waiting))
            ->description(trans('app.admin.widgets.waiting_hint'))
            ->color($waiting > 0 ? 'warning' : 'gray')
            ->icon(Heroicon::OutlinedClock)
            ->url(OrderResource::getUrl('index', ['activeTab' => 'waiting']));
        $stats[] = Stat::make(trans('app.admin.widgets.new_events'), $n($newEvents))
            ->description(trans('admin.dashboard.published_today', ['count' => $n($publishedToday)]))
            ->icon(Heroicon::OutlinedCalendarDays)
            ->url(EventResource::getUrl('index'));
        $stats[] = Stat::make(trans('admin.dashboard.published'), $n($published))
            ->description(trans('admin.dashboard.published_hint'))
            ->icon(Heroicon::OutlinedPaperAirplane);
        $stats[] = Stat::make(trans('admin.dashboard.new_users'), $n($newUsers))
            ->description(trans('admin.dashboard.new_users_month', ['count' => $n($newUsersMonth)]))
            ->icon(Heroicon::OutlinedUserPlus)
            ->url(UserResource::getUrl('index'));

        return $stats;
    }
}
