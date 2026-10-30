<?php

use App\Models\HallLayout;
use App\Models\SeatAssignment;
use App\Models\User;
use App\Services\HallGenerator;
use App\Services\Invite\GuestDayState;
use App\Services\Pdf\PdfRenderer;
use App\Services\Seating\HallLayoutData;
use App\Services\Seating\HallTemplates;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SeatingFixtures;

/*
| Every new shape works where the hall is used (phase 8B): the planner and
| its saves, auto-seat, the guest's mini-map and the printables.
*/

/** A Signature event in the garden starter hall (rows, banquet tables, sofas, a kids table). */
function p8bGardenEvent(array $families): array
{
    [$event, $f] = SeatingFixtures::event($families);
    $event->hallLayout->update(['layout' => app(HallTemplates::class)->garden()]);

    return [$event->fresh(), $f];
}

it('plans seats at banquet tables, sofas and rows, up to their last chair', function () {
    [$event, $f] = p8bGardenEvent([['Big family', 12], ['Couple', 2]]);
    $banquet = collect($event->hallLayout->layout['tables'])->firstWhere('shape', 'rect');
    $sofa = collect($event->hallLayout->layout['tables'])->firstWhere('shape', 'sofa');

    $rows = array_map(fn ($s) => ['household_id' => $f['Big family']->id, 'table_id' => $banquet['id'], 'seat_index' => $s], range(0, 11));
    $rows[] = ['household_id' => $f['Couple']->id, 'table_id' => $sofa['id'], 'seat_index' => 0];
    $rows[] = ['household_id' => $f['Couple']->id, 'table_id' => $sofa['id'], 'seat_index' => 2];

    $this->actingAs($event->owner)->putJson("/events/{$event->id}/seats", ['assignments' => $rows])->assertOk()->assertJsonCount(14, 'assignments');

    // a sofa of three has no fourth seat
    $rows[13]['seat_index'] = 3;
    $this->actingAs($event->owner)->putJson("/events/{$event->id}/seats", ['assignments' => $rows])->assertUnprocessable();

    $this->actingAs($event->owner)->get("/dashboard/events/{$event->id}/seating")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('hall.layout.tables.0.shape', 'row')->where('warnings', []));
});

it('accepts seats past the 24th chair of a long banquet table', function () {
    [$event, $f] = SeatingFixtures::event([['Clan', 30]]);
    $layout = $event->hallLayout->layout;
    $layout['tables'][0] = ['id' => 'T1', 'section' => $layout['tables'][0]['section'], 'shape' => 'rect', 'x' => 200, 'y' => 380, 'seats' => 32, 'w' => 340, 'h' => 30, 'rotation' => 0, 'sides' => 'both', 'heads' => true, 'chairR' => 6];
    $event->hallLayout->update(['layout' => HallLayoutData::normalize($layout)]);

    $rows = array_map(fn ($s) => ['household_id' => $f['Clan']->id, 'table_id' => 'T1', 'seat_index' => $s], range(0, 29));
    $this->actingAs($event->owner)->putJson("/events/{$event->id}/seats", ['assignments' => $rows])->assertOk();
    expect(SeatAssignment::query()->max('seat_index'))->toBe(29);
});

it('auto-seats a garden hall with the children at the kids table', function () {
    [$event, $f] = p8bGardenEvent([['With kids', 5, 3], ['Adults', 4]]);
    $kidsTable = collect($event->hallLayout->layout['tables'])->firstWhere('kids', true)['id'];

    $result = $this->actingAs($event->owner)->postJson("/events/{$event->id}/seating/auto")->assertOk()->json();
    $atKids = collect($result['assignments'])->where('table_id', $kidsTable);

    expect($atKids)->toHaveCount(3)
        ->and($atKids->pluck('household_id')->unique()->all())->toBe([$f['With kids']->id])
        ->and(collect($result['warnings'])->where('type', 'split'))->toBeEmpty();
});

it('draws banquet tables, sofas and rows on the guest mini-map', function () {
    [$event, $f] = p8bGardenEvent([['Ali', 2]]);
    $event->invitation->update(['published_at' => now()]);
    $banquet = collect($event->hallLayout->layout['tables'])->firstWhere('shape', 'rect');
    SeatAssignment::query()->create(['event_id' => $event->id, 'household_id' => $f['Ali']->id, 'table_id' => $banquet['id'], 'seat_index' => 0]);

    $map = $this->getJson("/i/{$f['Ali']->token}/state")->assertOk()->json('seat.map');
    $mine = collect($map['tables'])->firstWhere(0, $map['mine']);

    [$hw, $hh] = HallLayoutData::extent($banquet);
    expect($map['mine'])->toBe(HallLayoutData::tableNumber($banquet['id']))
        ->and($mine)->toHaveCount(6)
        ->and($mine[4])->toBe((int) round($hw * 2))->and($mine[5])->toBe((int) round($hh * 2))
        // round tables keep their four numbers
        ->and(collect($map['tables'])->first(fn ($t) => count($t) === 4))->not->toBeNull();
});

it('prints every shape on the seating chart, with the kids table marked', function () {
    [$event, $f] = p8bGardenEvent([['عائلة خالد', 3]]);
    $kids = collect($event->hallLayout->layout['tables'])->firstWhere('kids', true);
    SeatAssignment::query()->create(['event_id' => $event->id, 'household_id' => $f['عائلة خالد']->id, 'table_id' => $kids['id'], 'seat_index' => 0]);
    $this->mock(PdfRenderer::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('render')->andReturnUsing(fn (string $html) => '%PDF-fake '.$html);
    });

    $chart = $this->actingAs($event->owner)->get("/events/{$event->id}/seating.pdf")->assertOk()->getContent();
    expect($chart)->toContain('class="h-table h-rect')->toContain('class="h-table h-sofa')->toContain('class="h-table h-row')
        ->toContain('h-sofa-back')->toContain('is-kids')->toContain('h-obstacle h-buffet')->toContain('كبار الضيوف · VIP')
        ->toContain('(أطفال · Kids)');
    // one chair circle per seat of every table
    expect(substr_count($chart, 'class="h-chair'))->toBe(HallLayoutData::totalSeats($event->hallLayout->layout));

    $cards = $this->actingAs($event->owner)->get("/events/{$event->id}/table-cards.pdf")->assertOk()->getContent();
    expect($cards)->toContain('Kids');
});

it('prints the seating chart with banquet tables as a real PDF', function () {
    if (! app(PdfRenderer::class)->available()) {
        $this->markTestSkipped('Chromium is not installed (OG_CHROME_PATH).');
    }
    [$event] = SeatingFixtures::event([['Ali', 2]]);
    $event->hallLayout->update(['layout' => HallLayoutData::normalize(app(HallGenerator::class)->generate(12, 10, 'split', 'rect'))]);

    $pdf = $this->actingAs($event->owner)->get("/events/{$event->id}/seating.pdf")->assertOk()->getContent();
    expect($pdf)->toStartWith('%PDF');
});

it('generates banquet halls from the endpoint', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/halls/generate', ['tables' => 10, 'chairs' => 12, 'shape' => 'rect'])
        ->assertOk()
        ->assertJsonPath('tables.0.shape', 'rect')
        ->assertJsonCount(12, 'tables.0.chairs');
});

it('maps any shape for the guest page in whole numbers', function () {
    $layout = app(HallTemplates::class)->garden();
    $map = GuestDayState::map($layout, 'T1');

    foreach ($map['tables'] as $t) {
        expect(count($t))->toBeIn([4, 6]);
        foreach (array_slice($t, 1) as $n) {
            expect($n)->toBeInt();
        }
    }
    expect(HallLayout::query()->count())->toBe(0);
});
