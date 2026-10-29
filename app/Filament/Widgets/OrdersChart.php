<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Services\Digits;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

/**
 * Orders per day over the last 30 days: placed, and paid or approved. Counts,
 * not money, so every currency can share one chart.
 */
class OrdersChart extends ChartWidget
{
    public const DAYS = 30;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = null;

    public function getHeading(): string
    {
        return trans('admin.dashboard.chart', ['days' => Digits::localize(self::DAYS, app()->getLocale())]);
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array{labels: list<string>, placed: list<int>, paid: list<int>}
     */
    public static function series(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $from = $today->subDays(self::DAYS - 1);

        $count = fn (string $column, bool $fulfilled) => Order::query()
            ->where($column, '>=', $from)
            ->when($fulfilled, fn ($q) => $q->fulfilled())
            ->groupBy('day')
            ->select(DB::raw("date({$column}) as day"), DB::raw('count(*) as orders'))
            ->pluck('orders', 'day');

        $placed = $count('created_at', false);
        $paid = $count('paid_at', true);

        $labels = $placedSeries = $paidSeries = [];
        for ($day = $from; $day->lte($today); $day = $day->addDay()) {
            $key = $day->toDateString();
            $labels[] = Digits::localize($day->translatedFormat('j M'), app()->getLocale());
            $placedSeries[] = (int) ($placed[$key] ?? 0);
            $paidSeries[] = (int) ($paid[$key] ?? 0);
        }

        return ['labels' => $labels, 'placed' => $placedSeries, 'paid' => $paidSeries];
    }

    protected function getData(): array
    {
        $series = static::series();

        return [
            'datasets' => [
                [
                    'label' => trans('admin.dashboard.placed'),
                    'data' => $series['placed'],
                    'borderColor' => '#2563eb',
                    'borderWidth' => 2,
                    'backgroundColor' => '#2563eb',
                    'pointRadius' => 0,
                    'pointHoverRadius' => 4,
                    'tension' => 0,
                ],
                [
                    'label' => trans('admin.dashboard.paid'),
                    'data' => $series['paid'],
                    'borderColor' => '#e11d48',
                    'borderWidth' => 2,
                    'pointRadius' => 0,
                    'pointHoverRadius' => 4,
                    'backgroundColor' => 'rgba(225,29,72,.12)',
                    'fill' => true,
                    'tension' => 0,
                ],
            ],
            'labels' => $series['labels'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }
}
