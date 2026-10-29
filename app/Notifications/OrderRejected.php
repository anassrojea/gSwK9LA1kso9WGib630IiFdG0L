<?php

namespace App\Notifications;

/**
 * An admin rejected the transfer, with the reason.
 */
class OrderRejected extends OrderNotification
{
    protected function kind(): string
    {
        return 'rejected';
    }
}
