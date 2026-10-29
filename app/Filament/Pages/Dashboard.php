<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

/**
 * "Today" (plan section 4.3): App\Filament\Widgets\PaymentsOverview and OrdersChart.
 */
class Dashboard extends BaseDashboard
{
    public static function getNavigationLabel(): string
    {
        return trans('admin.dashboard.title');
    }

    public function getTitle(): string|Htmlable
    {
        return trans('admin.dashboard.title');
    }

    public function getColumns(): int|array
    {
        return 2;
    }
}
