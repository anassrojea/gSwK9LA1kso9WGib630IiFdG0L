<?php

namespace App\Services\Storage;

use App\Models\Event;
use App\Services\Entitlements;
use Carbon\CarbonImmutable;

/**
 * How long an event's photos and uploads are kept (plan section 11):
 * events.storage_until = the event's date + the plan's limits.storage_days,
 * set when the event is published or an order for it is paid. Photo cloud
 * add-ons (OrderFulfillment) and the admin's "Extend storage"
 * (App\Services\Admin\EventStorage) push it further; the later date always wins.
 * A plan without storage_days (null) keeps media for good: nothing is set.
 *
 * events:expire-storage warns, purges and archives once the date has passed.
 */
class StoragePeriod
{
    public function __construct(private Entitlements $entitlements) {}

    /**
     * The date the plan gives, or null when the plan keeps media for good.
     */
    public function planDate(Event $event): ?CarbonImmutable
    {
        $days = $this->entitlements->limit($event, 'storage_days');
        if ($days === null) {
            return null;
        }

        $date = $event->starts_at
            ? CarbonImmutable::parse($event->starts_at)->setTimezone($event->timezone ?: config('app.timezone'))->startOfDay()
            : CarbonImmutable::today();

        return CarbonImmutable::parse($date->toDateString())->addDays(max(0, $days));
    }

    /**
     * Push storage_until to the plan's date when that is later. The caller saves the event.
     *
     * @return bool whether storage_until changed
     */
    public function apply(Event $event): bool
    {
        $date = $this->planDate($event);
        if ($date === null) {
            return false;
        }

        if ($event->storage_until && $event->storage_until->toDateString() >= $date->toDateString()) {
            return false;
        }

        $event->storage_until = $date->toDateString();

        return true;
    }
}
