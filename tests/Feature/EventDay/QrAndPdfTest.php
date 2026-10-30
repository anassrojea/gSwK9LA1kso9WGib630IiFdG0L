<?php

use App\Models\Event;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\Rsvp;
use App\Models\SeatAssignment;
use App\Models\User;
use App\Services\Pdf\PdfRenderer;
use App\Services\Qr\QrCodes;
use chillerlan\QRCode\QRCode;
use Tests\Support\DashboardFixtures;
use Tests\Support\SeatingFixtures;

it('serves each household\'s personal link as a cacheable SVG QR code with error correction H', function () {
    [, $h] = DashboardFixtures::event();
    $token = $h['attending']->token;
    $opens = $h['attending']->open_count;

    $response = $this->get("/i/{$token}/qr.svg")->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml; charset=utf-8');

    $svg = $response->getContent();
    expect($svg)->toStartWith('<?xml')->toContain('<svg')->toContain('viewBox')
        ->and(simplexml_load_string($svg))->not->toBeFalse()
        ->and($response->headers->get('Cache-Control'))->toContain('public')->toContain('s-maxage=604800')
        ->and($h['attending']->fresh()->open_count)->toBe($opens);

    // the same code, decoded: the personal link at error correction level H
    $decoded = (new QRCode)->readFromBlob(app(QrCodes::class)->png(app(QrCodes::class)->url($h['attending'])));
    expect((string) $decoded)->toEndWith("/i/{$token}")
        ->and((string) $decoded->eccLevel)->toBe('H')
        ->and(app(QrCodes::class)->url($h['attending']))->toEndWith("/i/{$token}");

    $this->get("/i/{$token}/qr.svg", ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
});

it('has no QR code for free events, unknown tokens or unpublished invitations', function () {
    $event = Event::factory()->create(['plan_id' => null]);
    Invitation::factory()->for($event)->create();
    $free = Household::factory()->for($event)->create();

    $this->get("/i/{$free->token}/qr.svg")->assertNotFound();
    $this->get('/i/NoSuchToken99/qr.svg')->assertNotFound();

    [$premium, $h] = DashboardFixtures::event();
    $premium->invitation->update(['published_at' => null]);
    $this->get("/i/{$h['opened']->token}/qr.svg")->assertNotFound();
});

it('shows the door QR on the ticket only to attending guests of events with QR check-in', function () {
    [, $f] = SeatingFixtures::event([['Ali', 2], ['Silent', 0]]);
    [, $h] = DashboardFixtures::event();

    expect($this->getJson("/i/{$f['Ali']->token}/state")->json('qr'))->toBe("/i/{$f['Ali']->token}/qr.svg")
        ->and($this->getJson("/i/{$f['Silent']->token}/state")->json('qr'))->toBeNull()
        ->and($this->getJson("/i/{$h['attending']->token}/state")->json('qr'))->toBeNull();
});

function p7aPdfOrSkip(): void
{
    if (! app(PdfRenderer::class)->available()) {
        test()->markTestSkipped('Chromium is not installed (OG_CHROME_PATH).');
    }
}

it('prints the seating chart and the table cards as PDFs', function () {
    p7aPdfOrSkip();
    [$event, $f] = SeatingFixtures::event([['عائلة خالد العلي', 3], ['Huda', 2]]);
    foreach ([0, 1, 2] as $seat) {
        SeatAssignment::query()->create(['event_id' => $event->id, 'household_id' => $f['عائلة خالد العلي']->id, 'table_id' => 'T1', 'seat_index' => $seat]);
    }

    foreach (["/events/{$event->id}/seating.pdf", "/events/{$event->id}/table-cards.pdf"] as $url) {
        $response = $this->actingAs($event->owner)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect($response->getContent())->toStartWith('%PDF');
    }
});

it('renders the printables bilingual, with names, numbers and the hall', function () {
    [$event, $f] = SeatingFixtures::event([['عائلة خالد العلي', 3]]);
    SeatAssignment::query()->create(['event_id' => $event->id, 'household_id' => $f['عائلة خالد العلي']->id, 'table_id' => 'T7', 'seat_index' => 0]);
    $this->mock(PdfRenderer::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('render')->andReturnUsing(fn (string $html) => '%PDF-fake '.$html);
    });

    $chart = $this->actingAs($event->owner)->get("/events/{$event->id}/seating.pdf")->assertOk()->getContent();
    // the hall has tables T1-T4 only, so T7 is not drawn; table 1 of the hall is
    expect($chart)->toContain('مخطط الجلوس')->toContain('Seating chart')->toContain('<svg class="hall"');

    SeatAssignment::query()->update(['table_id' => 'T2']);
    $cards = $this->actingAs($event->owner)->get("/events/{$event->id}/table-cards.pdf")->assertOk()->getContent();
    expect($cards)->toContain('عائلة خالد العلي')->toContain('طاولة')->toContain('Table')->toContain('٢');
});

it('prints every family\'s QR code for paper invitations', function () {
    [$event, $h] = DashboardFixtures::event();
    $this->mock(PdfRenderer::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('render')->andReturnUsing(fn (string $html) => '%PDF-fake '.$html);
    });

    $html = $this->actingAs($event->owner)->get("/events/{$event->id}/qr-codes.pdf")->assertOk()->getContent();

    expect(substr_count($html, '<svg'))->toBe(5)->and($html)->toContain('عائلة ستحضر');
});

it('keeps the printables to the event\'s team and the seating ones to the seating feature', function () {
    [$event] = SeatingFixtures::event([['Ali', 2]]);
    [$premium] = DashboardFixtures::event();

    $this->actingAs(User::factory()->create())->get("/events/{$event->id}/seating.pdf")->assertForbidden();
    $this->actingAs($premium->owner)->get("/events/{$premium->id}/seating.pdf")->assertForbidden();
    $this->actingAs(User::factory()->create())->get("/events/{$event->id}/qr-codes.pdf")->assertForbidden();
});

it('says so when Chromium is missing instead of failing', function () {
    [$event] = SeatingFixtures::event([['Ali', 2]]);
    $this->mock(PdfRenderer::class, fn ($mock) => $mock->shouldReceive('available')->andReturn(false));

    $this->actingAs($event->owner)->get("/events/{$event->id}/seating.pdf")->assertStatus(503);
});

it('counts meals per section from confirmed replies only', function () {
    [$event, $f] = SeatingFixtures::event([['Ali', 4, 2, 'men'], ['Huda', 3, 1, 'women'], ['Omar', 2], ['No', 0]]);
    Rsvp::query()->where('household_id', $f['Ali']->id)->update(['extras' => ['meal' => 'Lamb']]);
    Rsvp::query()->where('household_id', $f['Omar']->id)->update(['extras' => ['meal' => 'Lamb']]);
    Rsvp::query()->where('household_id', $f['Huda']->id)->update(['extras' => ['meal' => 'Fish']]);
    Rsvp::factory()->create(['household_id' => Household::factory()->for($event)->create()->id, 'attending' => false, 'people_count' => 0, 'kids_count' => 0]);

    $meals = $this->actingAs($event->owner)->getJson("/dashboard/events/{$event->id}/day")->assertOk()->json('meals');

    expect($meals['sections']['men'])->toBe(['families' => 1, 'people' => 4, 'adults' => 2, 'kids' => 2])
        ->and($meals['sections']['women'])->toBe(['families' => 1, 'people' => 3, 'adults' => 2, 'kids' => 1])
        ->and($meals['sections']['none'])->toBe(['families' => 1, 'people' => 2, 'adults' => 2, 'kids' => 0])
        ->and($meals['total']['people'])->toBe(9)
        ->and($meals['meals'])->toBe([['meal' => 'Lamb', 'people' => 6], ['meal' => 'Fish', 'people' => 3]]);
});
