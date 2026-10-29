<?php

namespace App\Notifications;

/**
 * A transfer order got no receipt within 48 hours.
 */
class OrderExpired extends OrderNotification
{
    protected function kind(): string
    {
        return 'expired';
    }
}
