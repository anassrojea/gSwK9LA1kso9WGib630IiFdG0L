<?php

namespace App\Services\Seating;

use App\Models\Event;
use App\Models\HallLayout;
use App\Models\OrgMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Saved hall layouts a host can pick for an event, and saving the hall from
 * the seating planner. A venue's hall is reused by many events, so nudging it
 * from one event's planner saves a copy for that event instead of moving the
 * venue's tables for everybody.
 */
class HallLayouts
{
    public function __construct(private SeatingPlan $plan) {}

    /**
     * Layouts the user may use for this event: their own, the event's
     * organization's, and those of organizations they belong to.
     *
     * @return Builder<HallLayout>
     */
    public function availableQuery(User $user, ?Event $event = null): Builder
    {
        $orgIds = OrgMember::query()->where('user_id', $user->getKey())->pluck('org_id')->all();
        if ($event?->org_id) {
            $orgIds[] = $event->org_id;
        }

        return HallLayout::query()
            ->with('organization:id,name')
            ->where(fn (Builder $q) => $q->where('owner_id', $user->getKey())->orWhereIn('org_id', array_unique($orgIds)))
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function available(User $user, ?Event $event = null): array
    {
        return $this->availableQuery($user, $event)->get()->map(fn (HallLayout $h) => static::summary($h))->all();
    }

    public function canUse(User $user, HallLayout $hall, Event $event): bool
    {
        return $this->availableQuery($user, $event)->whereKey($hall->getKey())->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(HallLayout $hall): array
    {
        $layout = $hall->layout ?? [];

        return [
            'id' => $hall->id,
            'name' => $hall->name,
            'org' => $hall->organization?->name,
            'tables' => count($layout['tables'] ?? []),
            'seats' => HallLayoutData::totalSeats($layout),
            'split' => HallLayoutData::isSplit($layout),
        ];
    }

    /**
     * Link an existing hall to the event. Seats that no longer exist are dropped.
     *
     * @return int people who lost their seat
     */
    public function link(Event $event, HallLayout $hall): int
    {
        return DB::transaction(function () use ($event, $hall) {
            $event->forceFill(['hall_layout_id' => $hall->getKey()])->save();
            $event->setRelation('hallLayout', $hall);

            return $this->plan->pruneToLayout($event, $hall->layout);
        });
    }

    /**
     * Save the planner's hall (generated or nudged). The event's own hall is
     * updated in place; a hall shared with other events, or one this user may
     * not edit, is saved as a new hall owned by the event's organization (or
     * the user) and linked to the event.
     *
     * @return array{hall: HallLayout, dropped: int}
     */
    public function saveForEvent(User $user, Event $event, array $layout, ?string $name): array
    {
        $layout = HallLayoutData::normalize($layout, $name);

        return DB::transaction(function () use ($user, $event, $layout) {
            $current = $event->hallLayout;
            $sharedElsewhere = $current && Event::query()->where('hall_layout_id', $current->getKey())->whereKeyNot($event->getKey())->exists();

            if ($current && ! $sharedElsewhere && $user->can('update', $current)) {
                $current->update(['name' => $layout['name'], 'layout' => $layout]);
                $hall = $current;
            } else {
                $hall = HallLayout::query()->create([
                    'org_id' => $event->org_id,
                    'owner_id' => $event->org_id ? null : $user->getKey(),
                    'name' => $layout['name'],
                    'layout' => $layout,
                ]);
            }

            return ['hall' => $hall, 'dropped' => $this->link($event, $hall)];
        });
    }

    public function inUse(HallLayout $hall): int
    {
        return Event::query()->where('hall_layout_id', $hall->getKey())->count();
    }
}
