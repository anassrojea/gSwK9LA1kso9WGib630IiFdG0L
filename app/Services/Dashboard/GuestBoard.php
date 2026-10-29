<?php

namespace App\Services\Dashboard;

use App\Models\Checkin;
use App\Models\Event;
use App\Models\Household;
use App\Models\Reminder;
use App\Services\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * The numbers and the guest list of the host dashboard (plan section 4.1:
 * "numbers on top, one list below, actions on each row"). The same rows and
 * stats go out in the live broadcasts, so the page and the socket agree.
 */
class GuestBoard
{
    public const NOT_OPENED = 'not_opened';

    public const OPENED = 'opened';

    public const ATTENDING = 'attending';

    public const DECLINED = 'declined';

    public const STATUSES = [self::NOT_OPENED, self::OPENED, self::ATTENDING, self::DECLINED];

    /** Reminder audiences: never opened, opened but did not reply, or both. */
    public const AUDIENCES = ['never', 'opened', 'both'];

    public function __construct(private Entitlements $entitlements) {}

    /**
     * A reply counts as an open: a guest cannot answer without opening the link.
     */
    public static function status(Household $household): string
    {
        $rsvp = $household->rsvp;

        return match (true) {
            $rsvp !== null && $rsvp->attending => self::ATTENDING,
            $rsvp !== null => self::DECLINED,
            $household->first_opened_at !== null => self::OPENED,
            default => self::NOT_OPENED,
        };
    }

    /**
     * @return array<string, int|bool|null>
     */
    public function stats(Event $event): array
    {
        $row = DB::table('households')
            ->leftJoin('rsvps', 'rsvps.household_id', '=', 'households.id')
            ->where('households.event_id', $event->getKey())
            ->selectRaw(implode(', ', [
                'count(*) as invited',
                'sum(case when households.first_opened_at is not null or rsvps.id is not null then 1 else 0 end) as opened',
                'count(rsvps.id) as replied',
                'sum(case when rsvps.attending then 1 else 0 end) as attending_families',
                'sum(case when rsvps.attending then rsvps.people_count else 0 end) as attending',
                'sum(case when rsvps.attending then rsvps.kids_count else 0 end) as kids',
                'sum(case when rsvps.id is not null and not rsvps.attending then 1 else 0 end) as declined',
                'sum(case when households.first_opened_at is not null and rsvps.id is null then 1 else 0 end) as opened_not_replied',
                'sum(case when households.first_opened_at is null and rsvps.id is null then 1 else 0 end) as never_opened',
                'sum(households.seats_allowed) as seats_allowed',
            ]))
            ->first();

        $n = fn (string $key) => (int) ($row->{$key} ?? 0);

        $arrivals = Checkin::query()
            ->where('event_id', $event->getKey())
            ->selectRaw('count(distinct household_id) as families, coalesce(sum(people_in), 0) as people')
            ->first();

        $limit = $this->entitlements->limit($event, 'guests');
        $daysLeft = static::daysLeft($event);

        return [
            'invited' => $n('invited'),
            'opened' => $n('opened'),
            'replied' => $n('replied'),
            'attending' => $n('attending'),
            'attending_families' => $n('attending_families'),
            'kids' => $n('kids'),
            'declined' => $n('declined'),
            'opened_not_replied' => $n('opened_not_replied'),
            'never_opened' => $n('never_opened'),
            'seats_allowed' => $n('seats_allowed'),
            // seats handed out that nobody has confirmed yet
            'seats_left' => max(0, $n('seats_allowed') - $n('attending')),
            // the plan's soft guest limit (plan section 16), null when unlimited
            'guest_limit' => $limit,
            'limit_left' => $limit === null ? null : max(0, $limit - $n('seats_allowed')),
            'days_left' => $daysLeft,
            'is_event_day' => $daysLeft === 0,
            'arrived_families' => (int) ($arrivals->families ?? 0),
            'arrived_people' => (int) ($arrivals->people ?? 0),
        ];
    }

    /**
     * Whole days from today to the event day, both in the event's timezone.
     * 0 on the day itself, negative afterwards, null without a date.
     */
    public static function daysLeft(Event $event, ?CarbonImmutable $now = null): ?int
    {
        if (! $event->starts_at) {
            return null;
        }

        $timezone = $event->timezone ?: config('app.timezone');
        $today = ($now ?? CarbonImmutable::now())->setTimezone($timezone)->startOfDay();
        $day = $event->starts_at->setTimezone($timezone)->startOfDay();

        return (int) round($today->diffInDays($day, false));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(Event $event): array
    {
        $event->loadMissing('invitation');
        $templates = WhatsApp::templates($event);

        return $this->query()
            ->where('event_id', $event->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Household $h) => $this->present($event, $h, $templates))
            ->all();
    }

    /**
     * One row, freshly loaded (for broadcasts and single-row updates).
     *
     * @return array<string, mixed>
     */
    public function row(Household $household): array
    {
        $fresh = $this->query()->whereKey($household->getKey())->firstOrFail();
        $event = $household->event;

        return $this->present($event, $fresh, WhatsApp::templates($event));
    }

    /**
     * @return Builder<Household>
     */
    public function query(): Builder
    {
        return Household::query()
            ->with([
                'rsvp',
                'seatAssignments' => fn (Relation $q) => $q->select('id', 'household_id', 'table_id')->orderBy('seat_index'),
                'checkins' => fn (Relation $q) => $q->select('id', 'household_id', 'people_in', 'scanned_at')->orderBy('scanned_at'),
            ])
            ->withMax(['reminders as last_reminded_at' => fn (Builder $q) => $q->where('status', 'sent')], 'sent_at');
    }

    /**
     * @param  array{invite: string, reminder: string}  $templates
     * @return array<string, mixed>
     */
    private function present(Event $event, Household $h, array $templates): array
    {
        $rsvp = $h->rsvp;
        $checkin = $h->checkins->first();
        $message = WhatsApp::message($event, $h, 'invite', $templates);
        $lastReminded = $h->getAttribute('last_reminded_at');

        return [
            'id' => $h->id,
            'name' => $h->display_name,
            'phone' => $h->phone,
            'seats' => $h->seats_allowed,
            'tags' => array_values($h->tags ?? []),
            'status' => static::status($h),
            'people' => $rsvp?->attending ? $rsvp->people_count : null,
            'kids' => $rsvp?->attending ? $rsvp->kids_count : null,
            'note' => $rsvp?->message,
            'meal' => $rsvp?->extras['meal'] ?? null,
            'allergies' => $rsvp?->extras['allergies'] ?? null,
            'responded_at' => $rsvp?->responded_at?->toIso8601String(),
            'table' => $h->seatAssignments->first()?->table_id,
            'open_count' => $h->open_count,
            'first_opened_at' => $h->first_opened_at?->toIso8601String(),
            'last_opened_at' => $h->last_opened_at?->toIso8601String(),
            'last_reminded_at' => $lastReminded ? CarbonImmutable::parse($lastReminded)->toIso8601String() : null,
            'checked_in_at' => $checkin?->scanned_at?->toIso8601String(),
            'people_in' => $checkin ? (int) $h->checkins->sum('people_in') : null,
            'link' => WhatsApp::link($h),
            'whatsapp_url' => WhatsApp::url($h->phone, $message),
        ];
    }

    /**
     * Households a reminder goes to: never opened, opened without replying, or both.
     *
     * @return Builder<Household>
     */
    public static function audience(Event $event, string $audience): Builder
    {
        return Household::query()
            ->where('event_id', $event->getKey())
            ->whereDoesntHave('rsvp')
            ->when($audience === 'never', fn (Builder $q) => $q->whereNull('first_opened_at'))
            ->when($audience === 'opened', fn (Builder $q) => $q->whereNotNull('first_opened_at'));
    }

    /**
     * Last sent reminder per household of the event.
     *
     * @return array<string, string>
     */
    public static function lastReminders(Event $event): array
    {
        return Reminder::query()
            ->where('event_id', $event->getKey())
            ->where('status', 'sent')
            ->groupBy('household_id')
            ->selectRaw('household_id, max(sent_at) as sent_at')
            ->pluck('sent_at', 'household_id')
            ->all();
    }
}
