<?php

namespace App\Notifications;

/**
 * The order was paid (card) or approved (transfer): the plan and add-ons are unlocked.
 */
class OrderApproved extends OrderNotification
{
    protected function kind(): string
    {
        return 'approved';
    }
}
