<?php

use App\Models\AuditLog;
use App\Models\Checkin;
use App\Models\DoorPass;
use App\Models\Event;
use App\Models\OrgMember;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\DashboardFixtures;
use Tests\Support\SeatingFixtures;

/**
 * @return array{0: Event, 1: array, 2: array{pin: string, url: string, pass: array}}
 */
function p7aDoorPass(array $families = [['Ali', 2]]): array
{
    [$event, $f] = SeatingFixtures::event($families);
    $created = test()->actingAs($event->owner)
        ->postJson("/dashboard/events/{$event->id}/door-passes", ['label' => 'Main entrance'])
        ->assertCreated()
        ->json();
    auth()->logout();

    return [$event, $f, $created];
}

it('creates a door link with a 6-digit PIN that is stored only as a hash and shown once', function () {
    [$event, , $created] = p7aDoorPass();
    $pass = DoorPass::query()->sole();

    expect($created['pin'])->toMatch('/^\d{6}$/')
        ->and($pass->pin_hash)->not->toContain($created['pin'])
        ->and($created['url'])->toContain('signature=')
        // valid through the night: until 8 hours after a 20:00 start (config event_day.hours_after)
        ->and($pass->expires_at->toIso8601String())->toBe($event->starts_at->addHours(8)->toIso8601String())
        ->and(AuditLog::query()->where('action', 'door_pass.created')->exists())->toBeTrue();

    $list = test()->actingAs($event->owner)->getJson("/dashboard/events/{$event->id}/day")->assertOk()->json('door_passes');
    expect(json_encode($list))->not->toContain($created['pin'])->not->toContain('pin_hash');
});

it('lets staff without an account in with the link and the PIN, and records their scans', function () {
    [$event, $f, $created] = p7aDoorPass();

    $this->get($created['url'])->assertOk();
    $this->post($created['url'], ['pin' => $created['pin']])->assertRedirect("/door/{$event->id}");
    $this->get("/door/{$event->id}")->assertOk();

    $this->postJson("/door/{$event->id}/checkin", ['token' => $f['Ali']->token, 'scan_id' => (string) Str::uuid()])
        ->assertOk()->assertJsonPath('status', 'checked_in')->assertJsonPath('guest.checkins.0.by', trans('door.by_pass', ['label' => 'Main entrance']));

    $checkin = Checkin::query()->sole();
    expect($checkin->scanned_by)->toBeNull()
        ->and($checkin->door_pass_id)->toBe(DoorPass::query()->sole()->id)
        ->and(AuditLog::query()->where('action', 'door_pass.unlocked')->exists())->toBeTrue();
});

it('refuses a wrong PIN, a tampered link and an expired link', function () {
    [$event, , $created] = p7aDoorPass();
    $wrong = $created['pin'] === '000000' ? '111111' : '000000';

    $this->post($created['url'], ['pin' => $wrong])->assertSessionHasErrors('pin');
    $this->get("/door/{$event->id}")->assertRedirect('/login');

    $this->get(str_replace('signature=', 'signature=0', $created['url']))->assertForbidden();
    $this->post(preg_replace('/expires=\d+/', 'expires='.now()->addYears(5)->timestamp, $created['url']), ['pin' => $created['pin']])->assertForbidden();

    $this->travelTo(DoorPass::query()->sole()->expires_at->addMinute());
    $this->post($created['url'], ['pin' => $created['pin']])->assertForbidden();
});

it('rate-limits PIN guesses and locks the link after too many wrong ones', function () {
    [$event, , $created] = p7aDoorPass();
    $wrong = $created['pin'] === '000000' ? '111111' : '000000';
    config(['event_day.door.attempts_per_minute' => 3, 'event_day.door.lock_after' => 5]);

    foreach (range(1, 3) as $i) {
        $this->post($created['url'], ['pin' => $wrong])->assertSessionHasErrors(['pin' => trans('door.pass.wrong_pin')]);
    }
    // the fourth try within the minute is refused even with the right PIN
    $this->post($created['url'], ['pin' => $created['pin']])->assertSessionHasErrors('pin');
    $this->get("/door/{$event->id}")->assertRedirect('/login');

    RateLimiter::clear('door-pin:'.DoorPass::query()->sole()->id.'|127.0.0.1');
    $this->post($created['url'], ['pin' => $wrong]);
    $this->post($created['url'], ['pin' => $wrong])->assertSessionHasErrors(['pin' => trans('door.pass.locked')]);

    $pass = DoorPass::query()->sole();
    expect($pass->revoked_reason)->toBe('too_many_attempts')
        ->and(AuditLog::query()->where('action', 'door_pass.locked')->exists())->toBeTrue();

    RateLimiter::clear('door-pin:'.$pass->id.'|127.0.0.1');
    $this->post($created['url'], ['pin' => $created['pin']])->assertSessionHasErrors(['pin' => trans('door.pass.inactive')]);
});

it('ends open door sessions as soon as the host revokes the link', function () {
    [$event, $f, $created] = p7aDoorPass();
    $this->post($created['url'], ['pin' => $created['pin']]);
    $this->get("/door/{$event->id}")->assertOk();

    $pass = DoorPass::query()->sole();
    $this->actingAs($event->owner)->deleteJson("/dashboard/events/{$event->id}/door-passes/{$pass->id}")->assertOk()->assertJsonPath('door_passes.0.active', false);
    auth()->logout();

    $this->postJson("/door/{$event->id}/checkin", ['token' => $f['Ali']->token, 'scan_id' => (string) Str::uuid()])->assertUnauthorized();
    expect(AuditLog::query()->where('action', 'door_pass.revoked')->where('entity_id', $pass->id)->exists())->toBeTrue();
});

it('lets only those who may edit the event manage door links, on events with QR check-in', function () {
    [$event] = SeatingFixtures::event([['Ali', 2]]);

    $this->actingAs(DashboardFixtures::member($event, OrgMember::ROLE_DOOR))
        ->postJson("/dashboard/events/{$event->id}/door-passes", ['label' => 'x'])->assertForbidden();

    [$premium] = DashboardFixtures::event();
    $this->actingAs($premium->owner)->postJson("/dashboard/events/{$premium->id}/door-passes", ['label' => 'x'])->assertForbidden();
});

it('caps the number of active links and refuses them once the event is over', function () {
    [$event] = SeatingFixtures::event([['Ali', 2]]);
    config(['event_day.door.max_passes' => 2]);

    $this->actingAs($event->owner)->postJson("/dashboard/events/{$event->id}/door-passes", ['label' => 'A'])->assertCreated();
    $this->actingAs($event->owner)->postJson("/dashboard/events/{$event->id}/door-passes", ['label' => 'B'])->assertCreated();
    $this->actingAs($event->owner)->postJson("/dashboard/events/{$event->id}/door-passes", ['label' => 'C'])->assertUnprocessable();

    $event->update(['starts_at' => now()->subDays(3)]);
    DoorPass::query()->delete();
    $this->actingAs($event->owner)->postJson("/dashboard/events/{$event->id}/door-passes", ['label' => 'D'])
        ->assertUnprocessable()->assertJsonValidationErrors(['label' => trans('door.pass.event_over')]);
});

it('signs door links for the event they belong to only', function () {
    [$event, , $created] = p7aDoorPass();
    [$other] = SeatingFixtures::event([['X', 1]]);
    $pass = DoorPass::query()->sole();

    $url = URL::temporarySignedRoute('door.pass', now()->addHour(), ['event' => $other->id, 'pass' => $pass->id]);
    $this->get($url)->assertNotFound();
});
