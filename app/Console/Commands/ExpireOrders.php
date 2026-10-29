<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Payments\OrderFulfillment;
use Illuminate\Console\Command;

/**
 * Transfer orders still awaiting payment 48 hours after checkout expire
 * (plan section 12.2). Orders whose receipt was sent wait for the admin.
 * Scheduled in routes/console.php.
 */
class ExpireOrders extends Command
{
    protected $signature = 'orders:expire';

    protected $description = 'Expire transfer orders that got no receipt in time';

    public function handle(OrderFulfillment $fulfillment): int
    {
        $expired = 0;

        Order::query()
            ->where('method', 'transfer')
            ->where('status', 'awaiting_payment')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->each(function (Order $order) use ($fulfillment, &$expired) {
                $expired += $fulfillment->expire($order) ? 1 : 0;
            });

        $this->info("Expired {$expired} order(s).");

        return self::SUCCESS;
    }
}
