<?php

namespace App\Services\Journey;

use App\Models\AnniversaryReminder;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\User;
use App\Notifications\AnniversaryEmail;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\URL;

/**
 * The anniversary email (phase 8D, plan section 14.5), run daily by
 * journey:anniversaries: every year on the date of an event that took place,
 * its host gets an email that reopens the invitation and, while the storage
 * period lasts, the album, and suggests the next occasion (a baby welcome or
 * an anniversary invitation) with the loyalty discount.
 *
 *   - once per event and year: anniversary_reminders (event_id, year) is unique
 *   - a missed run still sends within journey.anniversary.grace_days
 *   - never to hosts who unsubscribed (users.marketing_opt_out, signed link in every email)
 *   - a 29 February event is remembered on 28 February in other years
 */
class Anniversaries
{
    /**
     * @return list<array{event: string, year: int}> what was sent (or would be, with $dryRun)
     */
    public function run(?CarbonImmutable $today = null, bool $dryRun = false): array
    {
        if (! config('journey.anniversary.enabled', true)) {
            return [];
        }

        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $grace = (int) config('journey.anniversary.grace_days', 7);
        $sent = [];

        Event::query()
            ->whereNotNull('starts_at')
            ->whereNotNull('owner_id')
            ->whereIn('status', (array) config('journey.anniversary.statuses', ['active', 'ended', 'archived']))
            ->where('starts_at', '<=', $today->subYear()->endOfDay())
            ->whereHas('owner', fn ($q) => $q->where('marketing_opt_out', false)->whereNotNull('email'))
            ->with(['owner', 'invitation'])
            ->chunkById(200, function ($events) use ($today, $grace, $dryRun, &$sent) {
                foreach ($events as $event) {
                    $year = $this->dueYear($event, $today, $grace);
                    if ($year === null || AnniversaryReminder::query()->where('event_id', $event->getKey())->where('year', $year)->exists()) {
                        continue;
                    }
                    if ($dryRun) {
                        $sent[] = ['event' => $event->getKey(), 'year' => $year];

                        continue;
                    }

                    try {
                        AnniversaryReminder::query()->create(['event_id' => $event->getKey(), 'year' => $year, 'sent_at' => now()]);
                    } catch (UniqueConstraintViolationException) {
                        // another run got there first
                        continue;
                    }

                    $event->owner->notify(new AnniversaryEmail($event, $year));
                    AuditLog::record('event.anniversary_sent', $event, ['year' => $year]);
                    $sent[] = ['event' => $event->getKey(), 'year' => $year];
                }
            });

        return $sent;
    }

    /**
     * Which anniversary (1, 2, …) falls today or within the grace days before it, if any.
     */
    public function dueYear(Event $event, CarbonImmutable $today, int $grace): ?int
    {
        $start = CarbonImmutable::parse($event->starts_at)->setTimezone($event->timezone ?: config('app.timezone'))->startOfDay();

        for ($years = max(1, $today->year - $start->year - 1); $years <= $today->year - $start->year; $years++) {
            $date = $start->addYearsNoOverflow($years);
            if ($date->lte($today) && $date->diffInDays($today) <= $grace) {
                return $years;
            }
        }

        return null;
    }

    public static function unsubscribeUrl(User $user): string
    {
        return URL::signedRoute('marketing.unsubscribe', ['user' => $user->getKey()]);
    }
}
