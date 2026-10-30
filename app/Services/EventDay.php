<?php

namespace App\Services;

use App\Models\Event;
use Carbon\CarbonImmutable;

/**
 * "The invite changes on the day" (plan section 14.1): the window in which
 * the live day panel shows, in the event's own time zone. It opens
 * config('event_day.hours_before') before the start (never before midnight
 * of the event day) and closes config('event_day.hours_after') after it.
 */
class EventDay
{
    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public static function window(Event $event): ?array
    {
        if (! $event->starts_at) {
            return null;
        }

        $timezone = $event->timezone ?: config('app.timezone');
        $start = $event->starts_at->setTimezone($timezone);
        $from = $start->subHours(max(0, (int) config('event_day.hours_before')));
        $dayStart = $start->startOfDay();

        return [
            $from->lessThan($dayStart) ? $dayStart : $from,
            $start->addHours(max(1, (int) config('event_day.hours_after'))),
        ];
    }

    public static function isLive(Event $event, ?CarbonImmutable $now = null): bool
    {
        $window = static::window($event);
        $now ??= CarbonImmutable::now();

        return $window !== null && $now->betweenIncluded($window[0], $window[1]);
    }

    /**
     * Until when a door PIN link works: the end of the live window or the end
     * of the event day, whichever is later.
     */
    public static function doorUntil(Event $event): ?CarbonImmutable
    {
        $window = static::window($event);
        if (! $window) {
            return null;
        }

        $endOfDay = $event->starts_at->setTimezone($event->timezone ?: config('app.timezone'))->endOfDay();

        return $window[1]->greaterThan($endOfDay) ? $window[1] : $endOfDay;
    }
}
