<?php

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\HallLayout;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\OrgMember;
use App\Models\Rsvp;
use App\Models\SeatAssignment;
use App\Models\User;
use App\Services\HallGenerator;
use App\Services\Seating\HallChanges;
use App\Services\Seating\HallTemplates;
use Database\Seeders\PlanSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The hall designer's save (phase 8B): a hall that events use. Removing a
| table or chairs unassigns those seats in every event, after the venue
| confirmed "N events use this hall; M seats will be unassigned"; renamed
| tables keep their guests; audit_log and the planner say what happened.
*/

/**
 * A venue hall of 3 tables × 4 chairs used by two Signature events, with seats at T1, T2 and T3.
 *
 * @return array{0: HallLayout, 1: User, 2: list<Event>}
 */
function p8bUsedHall(): array
{
    app(PlanSeeder::class)->run();
    $org = Organization::factory()->venue()->create();
    $owner = User::factory()->create();
    OrgMember::factory()->create(['org_id' => $org->id, 'user_id' => $owner->id, 'role' => OrgMember::ROLE_OWNER]);
    $hall = HallLayout::query()->create(['org_id' => $org->id, 'name' => 'Ballroom', 'layout' => app(HallGenerator::class)->generate(3, 4, name: 'Ballroom')]);

    $events = [];
    foreach ([0, 1] as $n) {
        $event = Event::factory()->create(['hall_layout_id' => $hall->id, 'plan_id' => 'signature']);
        Invitation::factory()->for($event)->create();
        foreach (['T1' => 2, 'T2' => 4, 'T3' => 1] as $table => $people) {
            $h = Household::factory()->for($event)->create(['display_name' => "{$table} family {$n}", 'seats_allowed' => $people]);
            Rsvp::factory()->create(['household_id' => $h->id, 'attending' => true, 'people_count' => $people, 'kids_count' => 0]);
            for ($s = 0; $s < $people; $s++) {
                SeatAssignment::query()->forceCreate(['event_id' => $event->id, 'household_id' => $h->id, 'table_id' => $table, 'seat_index' => $s, 'person_label' => 'x', 'updated_at' => now()->subHour(), 'created_at' => now()->subHour()]);
            }
        }
        $events[] = $event;
    }

    return [$hall, $owner, $events];
}

it('says how many seats a change would unassign before saving', function () {
    [$hall, $owner] = p8bUsedHall();
    $layout = $hall->layout;
    unset($layout['tables'][2]);            // T3 (1 person per event) goes
    $layout['tables'][1]['seats'] = 3;      // T2 loses its 4th chair

    $this->actingAs($owner)->postJson("/dashboard/halls/{$hall->id}/impact", ['layout' => array_values($layout['tables']) ? $layout : []])
        ->assertOk()
        ->assertExactJson(['events' => 2, 'seats' => 4]);

    // nothing changed yet
    expect(SeatAssignment::query()->count())->toBe(14)->and($hall->fresh()->layout['tables'])->toHaveCount(3);
});

it('refuses to unassign seats without confirmation, then unassigns them in every event with an audit row', function () {
    [$hall, $owner, $events] = p8bUsedHall();
    $layout = $hall->layout;
    unset($layout['tables'][2]);
    $layout['tables'] = array_values($layout['tables']);
    $layout['tables'][1]['seats'] = 3;

    $this->actingAs($owner)->from("/dashboard/halls/{$hall->id}")
        ->put("/dashboard/halls/{$hall->id}", ['name' => 'Ballroom', 'layout' => $layout])
        ->assertSessionHasErrors('hall');
    expect(SeatAssignment::query()->count())->toBe(14)->and($hall->fresh()->layout['tables'])->toHaveCount(3);

    $this->actingAs($owner)->put("/dashboard/halls/{$hall->id}", ['name' => 'Ballroom', 'layout' => $layout, 'confirmed' => true])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($hall->fresh()->layout['tables'])->toHaveCount(2);
    foreach ($events as $event) {
        $seats = SeatAssignment::query()->where('event_id', $event->id)->get();
        expect($seats)->toHaveCount(5)
            ->and($seats->where('table_id', 'T3'))->toBeEmpty()
            ->and($seats->where('table_id', 'T2')->max('seat_index'))->toBe(2);
    }

    $hallRow = AuditLog::query()->where('action', HallChanges::ACTION_HALL)->sole();
    expect($hallRow->entity_id)->toBe($hall->id)->and($hallRow->actor_id)->toBe($owner->id)
        ->and($hallRow->data)->toMatchArray(['events' => 2, 'seats_unassigned' => 4]);
    $eventRows = AuditLog::query()->where('action', HallChanges::ACTION_EVENT)->get();
    expect($eventRows)->toHaveCount(2)->and($eventRows->pluck('data.count')->all())->toBe([2, 2]);

    // the host's planner flags it…
    $this->actingAs($events[0]->owner)->get("/dashboard/events/{$events[0]->id}/seating")
        ->assertInertia(fn (Assert $page) => $page->where('hall_change.count', 2)->has('hall_change.at'));

    // …until the host saves the seats again
    $this->travel(1)->minutes();
    $keep = SeatAssignment::query()->where('event_id', $events[0]->id)->get(['household_id', 'table_id', 'seat_index'])->toArray();
    $this->actingAs($events[0]->owner)->putJson("/events/{$events[0]->id}/seats", ['assignments' => $keep])->assertOk();
    $this->actingAs($events[0]->owner)->get("/dashboard/events/{$events[0]->id}/seating")
        ->assertInertia(fn (Assert $page) => $page->where('hall_change', null));
});

it('keeps guests at renumbered tables, even when two numbers swap', function () {
    [$hall, $owner, $events] = p8bUsedHall();
    $layout = $hall->layout;
    [$layout['tables'][0]['id'], $layout['tables'][2]['id']] = ['T3', 'T1'];   // T1 ↔ T3

    $this->actingAs($owner)->postJson("/dashboard/halls/{$hall->id}/impact", ['layout' => $layout, 'renames' => ['T1' => 'T3', 'T3' => 'T1']])
        ->assertExactJson(['events' => 2, 'seats' => 0]);
    $this->actingAs($owner)->put("/dashboard/halls/{$hall->id}", ['name' => 'Ballroom', 'layout' => $layout, 'renames' => ['T1' => 'T3', 'T3' => 'T1']])
        ->assertSessionHasNoErrors();

    $first = Household::query()->where('display_name', 'T1 family 0')->sole();
    expect(SeatAssignment::query()->where('household_id', $first->id)->pluck('table_id')->unique()->all())->toBe(['T3'])
        ->and(SeatAssignment::query()->count())->toBe(14)
        ->and(AuditLog::query()->where('action', HallChanges::ACTION_HALL)->sole()->data['renamed'])->toBe(2)
        ->and(AuditLog::query()->where('action', HallChanges::ACTION_EVENT)->count())->toBe(0);
});

it('ignores renames that point nowhere', function () {
    $old = app(HallGenerator::class)->generate(3, 4);
    $new = $old;
    $new['tables'][0]['id'] = 'T9';

    expect(HallChanges::cleanRenames($old, $new, ['T1' => 'T9', 'T2' => 'T9', 'T7' => 'T2', 'T3' => 'T3', 'T2x' => 'T1']))->toBe(['T1' => 'T9']);
});

it('saves a hall no event uses without asking', function () {
    [$hall, $owner] = p8bUsedHall();
    Event::query()->update(['hall_layout_id' => null]);
    $layout = $hall->layout;
    unset($layout['tables'][2]);

    $this->actingAs($owner)->put("/dashboard/halls/{$hall->id}", ['name' => 'Ballroom', 'layout' => $layout])->assertSessionHasNoErrors();
    expect($hall->fresh()->layout['tables'])->toHaveCount(2)->and(AuditLog::query()->count())->toBe(0);
});

it('lets only the venue team check or change its hall', function () {
    [$hall] = p8bUsedHall();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->postJson("/dashboard/halls/{$hall->id}/impact", ['layout' => $hall->layout])->assertForbidden();
    $this->actingAs($stranger)->put("/dashboard/halls/{$hall->id}", ['name' => 'X', 'layout' => $hall->layout, 'confirmed' => true])->assertForbidden();
});

it('offers starter halls to copy into the venue', function () {
    app(HallTemplates::class)->seed();
    $org = Organization::factory()->venue()->create();
    $staff = User::factory()->create();
    OrgMember::factory()->create(['org_id' => $org->id, 'user_id' => $staff->id, 'role' => OrgMember::ROLE_STAFF]);

    $this->actingAs($staff)->get('/dashboard/halls')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('templates', 3)->where('templates.0.key', 'ballroom')->has('halls', 0));

    $template = HallLayout::query()->where('template_key', 'garden')->sole();
    $this->actingAs($staff)->post("/dashboard/halls/{$template->id}/duplicate", ['org_id' => $org->id])->assertRedirect();

    $copy = HallLayout::query()->where('org_id', $org->id)->sole();
    expect($copy->template_key)->toBeNull()
        ->and($copy->layout['tables'])->toEqual($template->layout['tables'])
        ->and($copy->layout['id'])->not->toBe($template->layout['id']);

    // starter halls cannot be edited or deleted by venues
    $this->actingAs($staff)->put("/dashboard/halls/{$template->id}", ['name' => 'Mine', 'layout' => $template->layout])->assertForbidden();
    $this->actingAs($staff)->delete("/dashboard/halls/{$template->id}")->assertForbidden();
    // nor copied into somebody else's venue
    $this->actingAs($staff)->post("/dashboard/halls/{$template->id}/duplicate", ['org_id' => Organization::factory()->venue()->create()->id])->assertForbidden();
});

it('opens the designer with banquet tables, sofas and rows', function () {
    app(HallTemplates::class)->seed();
    $user = User::factory()->create();
    $hall = HallLayout::query()->create(['owner_id' => $user->id, 'name' => 'Garden', 'layout' => app(HallTemplates::class)->garden()]);

    $this->actingAs($user)->get("/dashboard/halls/{$hall->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('halls/Edit')
            ->where('urls.impact', "/dashboard/halls/{$hall->id}/impact")
            ->where('hall.layout.tables.0.shape', 'row')
            ->has('hall.layout.tables.0.chairs', 12)
            ->has('translations.halls.designer'));
});
