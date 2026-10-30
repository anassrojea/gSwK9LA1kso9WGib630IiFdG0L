<?php

use App\Models\Event;
use App\Models\HallLayout;
use App\Models\Household;
use App\Models\OrgMember;
use App\Models\Rsvp;
use App\Models\SeatAssignment;
use App\Models\User;
use App\Services\HallGenerator;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DashboardFixtures;
use Tests\Support\SeatingFixtures;

function p7aSeat(Household $h, string $table, int $seat): array
{
    return ['household_id' => $h->id, 'table_id' => $table, 'seat_index' => $seat];
}

it('shows the planner with the hall, confirmed families, seats, warnings and meal counts', function () {
    [$event, $f] = SeatingFixtures::event([['Ali', 3, 1, 'men'], ['Huda', 2, 0, 'women'], ['Declined', 0]]);
    Rsvp::query()->where('household_id', $f['Huda']->id)->update(['extras' => ['meal' => 'Fish']]);
    SeatAssignment::query()->create(['event_id' => $event->id, 'household_id' => $f['Ali']->id, 'table_id' => 'T1', 'seat_index' => 0]);

    $this->actingAs($event->owner)
        ->get("/dashboard/events/{$event->id}/seating")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('seating/Planner')
            ->where('locked', false)
            ->has('hall.layout.tables', 4)
            ->has('families', 2)
            ->where('families.0.name', 'Ali')
            ->where('families.0.kids', 1)
            ->has('assignments', 1)
            ->where('meals.sections.men.adults', 2)
            ->where('meals.sections.men.kids', 1)
            ->where('meals.meals.0', ['meal' => 'Fish', 'people' => 2])
            ->where('warnings', fn ($w) => collect($w)->where('type', 'unseated')->count() === 2)
            ->has('translations.seating.title'));
});

it('offers the seating add-on instead of the planner without the feature', function () {
    [$event] = DashboardFixtures::event();

    $this->actingAs($event->owner)
        ->get("/dashboard/events/{$event->id}/seating")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locked', true)->missing('families')->where('urls.upgrade', "/dashboard/events/{$event->id}/upgrade?addon=seating"));

    $this->actingAs($event->owner)->putJson("/events/{$event->id}/seats", ['assignments' => []])->assertForbidden();
    $this->actingAs($event->owner)->postJson("/events/{$event->id}/seating/auto")->assertForbidden();
});

it('saves the whole plan with person labels and replaces the previous one', function () {
    [$event, $f] = SeatingFixtures::event([['Ali', 3], ['Huda', 2]]);
    SeatAssignment::query()->create(['event_id' => $event->id, 'household_id' => $f['Huda']->id, 'table_id' => 'T4', 'seat_index' => 3]);

    $this->actingAs($event->owner)
        ->putJson("/events/{$event->id}/seats", ['assignments' => [
            p7aSeat($f['Ali'], 'T1', 0), p7aSeat($f['Ali'], 'T1', 1), p7aSeat($f['Ali'], 'T2', 0), p7aSeat($f['Huda'], 'T1', 2),
        ]])
        ->assertOk()
        ->assertJsonCount(4, 'assignments')
        ->assertJsonPath('warnings.0.type', 'split');

    expect(SeatAssignment::query()->where('event_id', $event->id)->orderBy('table_id')->orderBy('seat_index')->pluck('person_label')->all())
        ->toBe(['Ali 1/3', 'Ali 2/3', 'Huda 1/2', 'Ali 3/3']);
});

it('rejects chairs a table does not have, two people on one chair, and more seats than people', function () {
    [$event, $f] = SeatingFixtures::event([['Ali', 2], ['Huda', 2]], chairs: 4);
    $put = fn (array $rows) => $this->actingAs($event->owner)->putJson("/events/{$event->id}/seats", ['assignments' => $rows]);

    $put([p7aSeat($f['Ali'], 'T1', 4)])->assertUnprocessable()->assertJsonValidationErrors('assignments.0.seat_index');
    $put([p7aSeat($f['Ali'], 'T9', 0)])->assertUnprocessable()->assertJsonValidationErrors('assignments.0.table_id');
    $put([p7aSeat($f['Ali'], 'T1', 0), p7aSeat($f['Huda'], 'T1', 0)])->assertUnprocessable()->assertJsonValidationErrors('assignments.1.seat_index');
    $put([p7aSeat($f['Ali'], 'T1', 0), p7aSeat($f['Ali'], 'T1', 1), p7aSeat($f['Ali'], 'T1', 2)])->assertUnprocessable()->assertJsonValidationErrors('assignments.2.household_id');
    $put([['household_id' => $f['Ali']->id, 'table_id' => 'T1', 'seat_index' => -1]])->assertUnprocessable();

    expect(SeatAssignment::query()->count())->toBe(0);
});

it('only seats confirmed families of this event', function () {
    [$event, $f] = SeatingFixtures::event([['Ali', 2], ['No reply', 0]]);
    [, $other] = SeatingFixtures::event([['Elsewhere', 2]]);

    $this->actingAs($event->owner)->putJson("/events/{$event->id}/seats", ['assignments' => [p7aSeat($other['Elsewhere'], 'T1', 0)]])
        ->assertUnprocessable()->assertJsonValidationErrors('assignments.0.household_id');
    $this->actingAs($event->owner)->putJson("/events/{$event->id}/seats", ['assignments' => [p7aSeat($f['No reply'], 'T1', 0)]])
        ->assertUnprocessable()->assertJsonValidationErrors('assignments.0.household_id');
});

it('lets only those who may edit the event seat guests', function () {
    [$event, $f] = SeatingFixtures::event([['Ali', 2]]);
    $rows = ['assignments' => [p7aSeat($f['Ali'], 'T1', 0)]];

    $this->actingAs(User::factory()->create())->putJson("/events/{$event->id}/seats", $rows)->assertForbidden();
    $this->actingAs(DashboardFixtures::member($event, OrgMember::ROLE_DOOR))->putJson("/events/{$event->id}/seats", $rows)->assertForbidden();
    $this->actingAs(DashboardFixtures::member($event, OrgMember::ROLE_DOOR))->get("/dashboard/events/{$event->id}/seating")->assertForbidden();
    $this->actingAs(DashboardFixtures::member($event, OrgMember::ROLE_STAFF))->putJson("/events/{$event->id}/seats", $rows)->assertOk();
});

it('previews auto-seat without saving anything', function () {
    [$event] = SeatingFixtures::event([['Ali', 3, 1, 'men'], ['Huda', 2, 0, 'women'], ['Omar', 4]]);

    $response = $this->actingAs($event->owner)->postJson("/events/{$event->id}/seating/auto", ['mode' => 'all'])->assertOk();

    expect($response->json('assignments'))->toHaveCount(9)
        ->and($response->json('placed'))->toBe(3)
        ->and($response->json('warnings'))->toBe([])
        ->and($response->json('assignments.0.person_label'))->toEndWith('1/'.$response->json('assignments.0.person_label')[-1])
        ->and(SeatAssignment::query()->count())->toBe(0);

    // applying the proposal is a plain save
    $this->actingAs($event->owner)->putJson("/events/{$event->id}/seats", ['assignments' => $response->json('assignments')])->assertOk();
    expect(SeatAssignment::query()->count())->toBe(9);
});

it('saves a generated hall for the event and links a saved one', function () {
    [$event, $f] = SeatingFixtures::event([['Ali', 2]], tables: 4, chairs: 4);
    SeatAssignment::query()->create(['event_id' => $event->id, 'household_id' => $f['Ali']->id, 'table_id' => 'T4', 'seat_index' => 3]);

    // a smaller hall frees the seats that no longer exist
    $layout = app(HallGenerator::class)->generate(2, 3, 'mixed', name: 'Garden');
    $layout['tables'][0]['x'] = 123.4;
    $response = $this->actingAs($event->owner)->putJson("/events/{$event->id}/hall", ['layout' => $layout, 'name' => 'Garden'])
        ->assertOk()
        ->assertJsonPath('dropped', 1)
        ->assertJsonPath('hall.layout.tables.0.x', 123.4);

    // the event's own hall is updated in place
    expect($event->fresh()->hall_layout_id)->toBe($response->json('hall.id'))
        ->and(HallLayout::query()->find($response->json('hall.id'))->name)->toBe('Garden');

    $saved = HallLayout::query()->create(['owner_id' => $event->owner_id, 'name' => 'Other', 'layout' => app(HallGenerator::class)->generate(3, 6)]);
    $this->actingAs($event->owner)->putJson("/events/{$event->id}/hall", ['hall_id' => $saved->id])->assertOk()->assertJsonPath('hall.name', 'Other');
    expect($event->fresh()->hall_layout_id)->toBe($saved->id);

    $foreign = HallLayout::query()->create(['owner_id' => User::factory()->create()->id, 'name' => 'Not yours', 'layout' => app(HallGenerator::class)->generate(3, 6)]);
    $this->actingAs($event->owner)->putJson("/events/{$event->id}/hall", ['hall_id' => $foreign->id])->assertForbidden();
});

it('saves a copy instead of moving the tables of a hall other events share', function () {
    [$event] = SeatingFixtures::event([['Ali', 2]]);
    Event::factory()->create(['hall_layout_id' => $event->hall_layout_id]);
    $original = $event->hall_layout_id;

    $layout = $event->hallLayout->layout;
    $layout['tables'][0]['x'] = 50;
    $response = $this->actingAs($event->owner)->putJson("/events/{$event->id}/hall", ['layout' => $layout])->assertOk();

    expect($response->json('hall.id'))->not->toBe($original)
        ->and(HallLayout::query()->find($original)->layout['tables'][0]['x'])->not->toBe(50.0);
});

it('rejects a broken hall layout', function () {
    [$event] = SeatingFixtures::event([['Ali', 2]]);

    $this->actingAs($event->owner)->putJson("/events/{$event->id}/hall", ['layout' => ['size' => ['w' => 800, 'h' => 580], 'tables' => []]])
        ->assertUnprocessable()->assertJsonValidationErrors('layout.tables');
    $this->actingAs($event->owner)->putJson("/events/{$event->id}/hall", ['layout' => ['size' => ['w' => 800, 'h' => 580], 'tables' => [
        ['id' => 'T1', 'x' => 10, 'y' => 10, 'seats' => 4], ['id' => 'T1', 'x' => 10, 'y' => 10, 'seats' => 4],
    ]]])->assertUnprocessable()->assertJsonValidationErrors('layout.tables.1.id');
    $this->actingAs($event->owner)->putJson("/events/{$event->id}/hall", ['layout' => ['size' => ['w' => 800, 'h' => 580], 'tables' => [
        ['id' => 'T1', 'x' => 10, 'y' => 10, 'seats' => 99],
    ]]])->assertUnprocessable()->assertJsonValidationErrors('layout.tables.0.seats');
});

it('sets a family\'s section from the planner', function () {
    [$event, $f] = SeatingFixtures::event([['Ali', 2]]);

    $this->actingAs($event->owner)->patchJson("/events/{$event->id}/seating/section", ['household_id' => $f['Ali']->id, 'section' => 'men'])->assertOk();
    expect($f['Ali']->fresh()->section)->toBe('men');

    $this->actingAs($event->owner)->patchJson("/events/{$event->id}/seating/section", ['household_id' => $f['Ali']->id, 'section' => 'kids'])->assertUnprocessable();
    $this->actingAs($event->owner)->patchJson("/events/{$event->id}/seating/section", ['household_id' => $f['Ali']->id, 'section' => null])->assertOk();
    expect($f['Ali']->fresh()->section)->toBeNull();
});

it('shows a confirmed, seated guest their table and hall map in /state', function () {
    [$event, $f] = SeatingFixtures::event([['Ali', 2]]);
    $event->invitation->update(['published_at' => now()]);
    SeatAssignment::query()->create(['event_id' => $event->id, 'household_id' => $f['Ali']->id, 'table_id' => 'T3', 'seat_index' => 0]);

    $state = $this->getJson("/i/{$f['Ali']->token}/state")->assertOk();

    expect($state->json('table'))->toBe('T3')
        ->and($state->json('seat.number'))->toBe('3')
        ->and($state->json('seat.map.mine'))->toBe('3')
        ->and($state->json('seat.map.tables'))->toHaveCount(4)
        ->and($state->json('qr'))->toBe("/i/{$f['Ali']->token}/qr.svg");
});
