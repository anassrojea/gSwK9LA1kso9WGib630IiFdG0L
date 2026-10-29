<?php

namespace Tests\Support;

use App\Models\Event;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\OrgMember;
use App\Models\Rsvp;
use App\Models\User;

/**
 * A published event with one family in each dashboard state:
 *
 *   not_opened  2 seats, never opened
 *   opened      3 seats, opened, no reply
 *   attending   4 seats, opened, 3 people coming (1 child)
 *   declined    2 seats, opened, declined
 *   replied     2 seats, never logged an open, 2 people coming (a reply counts as an open)
 */
class DashboardFixtures
{
    /**
     * @return array{0: Event, 1: array<string, Household>}
     */
    public static function event(array $attributes = []): array
    {
        $event = Event::factory()->create($attributes + ['guest_limit' => 20]);
        Invitation::factory()->for($event)->create();

        $opened = now()->subHours(3);
        $make = fn (string $name, int $seats, bool $open, array $extra = []) => Household::factory()->for($event)->create([
            'display_name' => $name,
            'seats_allowed' => $seats,
            'first_opened_at' => $open ? $opened : null,
            'last_opened_at' => $open ? $opened : null,
            'open_count' => $open ? 1 : 0,
        ] + $extra);

        $h = [
            'not_opened' => $make('عائلة لم تفتح', 2, false, ['tags' => ['أقارب'], 'phone' => '+962791111111']),
            'opened' => $make('عائلة فتحت', 3, true, ['tags' => ['أصدقاء'], 'phone' => null]),
            'attending' => $make('عائلة ستحضر', 4, true, ['tags' => ['أقارب', 'جيران']]),
            'declined' => $make('عائلة اعتذرت', 2, true),
            'replied' => $make('Omar Family', 2, false),
        ];

        Rsvp::factory()->create(['household_id' => $h['attending']->id, 'attending' => true, 'people_count' => 3, 'kids_count' => 1]);
        Rsvp::factory()->create(['household_id' => $h['declined']->id, 'attending' => false, 'people_count' => 0, 'kids_count' => 0, 'message' => null]);
        Rsvp::factory()->create(['household_id' => $h['replied']->id, 'attending' => true, 'people_count' => 2, 'kids_count' => 0]);

        return [$event, $h];
    }

    public static function member(Event $event, string $role): User
    {
        $org = Organization::factory()->venue()->create();
        $event->update(['org_id' => $org->id]);
        $user = User::factory()->create();
        OrgMember::factory()->create(['org_id' => $org->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }
}
