<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /**
     * "Transfers waiting" first: the admin's daily queue (plan section 4.3).
     */
    public function getTabs(): array
    {
        $waiting = Order::query()->where('status', 'receipt_received')->count();

        return [
            'waiting' => Tab::make(trans('app.admin.orders.waiting'))
                ->badge($waiting ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('method', 'transfer')->where('status', 'receipt_received')),
            'awaiting' => Tab::make(trans('app.payments.status.awaiting_payment'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'awaiting_payment')),
            'done' => Tab::make(trans('app.admin.orders.done'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', Order::FULFILLED)),
            'all' => Tab::make(trans('app.admin.orders.all')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return Order::query()->where('status', 'receipt_received')->exists() ? 'waiting' : 'all';
    }
}
