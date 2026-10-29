<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Event;
use App\Models\Order;
use App\Services\Payments\Currencies;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The admin's "Today" (plan section 4.3): revenue per currency, new events,
 * transfers waiting for approval.
 */
class PaymentsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected ?string $pollingInterval = '60s';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $today = now()->startOfDay();

        $revenue = Order::query()
            ->fulfilled()
            ->where('paid_at', '>=', $today)
            ->groupBy('currency')
            ->selectRaw('currency, sum(total_cents) as total, count(*) as orders')
            ->get()
            ->keyBy('currency');

        $stats = [];
        foreach (Currencies::all() as $currency) {
            $row = $revenue[$currency] ?? null;
            $stats[] = Stat::make(trans('app.admin.widgets.revenue', ['currency' => $currency]), Currencies::format((int) ($row->total ?? 0), $currency, app()->getLocale()))
                ->description(trans_choice('app.admin.widgets.orders', (int) ($row->orders ?? 0), ['count' => (int) ($row->orders ?? 0)]))
                ->icon(Heroicon::OutlinedBanknotes);
        }

        $waiting = Order::query()->where('status', 'receipt_received')->count();

        $stats[] = Stat::make(trans('app.admin.widgets.new_events'), (string) Event::query()->where('created_at', '>=', $today)->count())
            ->icon(Heroicon::OutlinedCalendarDays);
        $stats[] = Stat::make(trans('app.admin.orders.waiting'), (string) $waiting)
            ->description(trans('app.admin.widgets.waiting_hint'))
            ->color($waiting > 0 ? 'warning' : 'gray')
            ->icon(Heroicon::OutlinedClock)
            ->url(OrderResource::getUrl('index', ['activeTab' => 'waiting']));

        return $stats;
    }
}
