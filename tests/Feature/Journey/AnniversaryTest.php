<?php

use App\Models\AnniversaryReminder;
use App\Models\Event;
use App\Models\User;
use App\Notifications\AnniversaryEmail;
use App\Services\Journey\Anniversaries;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
| The anniversary email (plan section 14.5): yearly, once per event and year,
| never after the host unsubscribed through the signed link.
*/

beforeEach(function () {
    Notification::fake();
    $this->owner = User::factory()->create(['email' => 'host@example.com']);
    $this->event = Event::factory()->create([
        'owner_id' => $this->owner->id, 'status' => 'ended', 'timezone' => 'Asia/Riyadh',
        'starts_at' => CarbonImmutable::parse('2025-11-05 17:00', 'UTC'),
    ]);
});

it('sends once on the first anniversary, even when run again', function () {
    $this->artisan('journey:anniversaries', ['--date' => '2026-11-04'])->expectsOutputToContain('0 anniversary');
    $this->artisan('journey:anniversaries', ['--date' => '2026-11-05'])->expectsOutputToContain('1 anniversary');
    $this->artisan('journey:anniversaries', ['--date' => '2026-11-05'])->expectsOutputToContain('0 anniversary');
    $this->artisan('journey:anniversaries', ['--date' => '2026-11-08'])->expectsOutputToContain('0 anniversary');

    Notification::assertSentToTimes($this->owner, AnniversaryEmail::class, 1);
    expect(AnniversaryReminder::query()->where('event_id', $this->event->id)->pluck('year')->all())->toBe([1]);

    // and again the next year
    $this->artisan('journey:anniversaries', ['--date' => '2027-11-05'])->expectsOutputToContain('1 anniversary');
    Notification::assertSentToTimes($this->owner, AnniversaryEmail::class, 2);
});

it('catches up within the grace days, but not later', function () {
    expect(app(Anniversaries::class)->run(CarbonImmutable::parse('2026-11-10')))->toHaveCount(1);

    $late = Event::factory()->create(['owner_id' => $this->owner->id, 'status' => 'ended', 'starts_at' => CarbonImmutable::parse('2025-10-01 17:00', 'UTC')]);
    expect(collect(app(Anniversaries::class)->run(CarbonImmutable::parse('2026-11-10')))->pluck('event'))->not->toContain($late->id);
});

it('skips drafts, recent events and hosts who unsubscribed', function () {
    Event::factory()->draft()->create(['owner_id' => $this->owner->id, 'starts_at' => CarbonImmutable::parse('2025-11-05 17:00')]);
    Event::factory()->create(['owner_id' => $this->owner->id, 'status' => 'active', 'starts_at' => CarbonImmutable::parse('2026-11-05 17:00')]);

    expect(app(Anniversaries::class)->run(CarbonImmutable::parse('2026-11-05'), dryRun: true))->toHaveCount(1);

    $this->owner->forceFill(['marketing_opt_out' => true])->save();
    expect(app(Anniversaries::class)->run(CarbonImmutable::parse('2026-11-05')))->toBe([]);
    Notification::assertNothingSent();
});

it('unsubscribes through the signed link in the email, with one-click headers', function () {
    $mail = (new AnniversaryEmail($this->event, 1))->toMail($this->owner);
    $text = implode("\n", [...$mail->introLines, ...$mail->outroLines]);
    preg_match('#https?://[^\s)]+/unsubscribe/[^\s)]+#', $text, $m);

    expect($mail->subject)->toContain($this->event->title)
        ->and($mail->actionUrl)->toContain("/dashboard/events/{$this->event->id}")
        ->and($m)->not->toBeEmpty();

    $this->get(preg_replace('#signature=[0-9a-f]+#', 'signature=bad', $m[0]))->assertForbidden();
    expect($this->owner->fresh()->marketing_opt_out)->toBeFalse();

    $this->get($m[0])->assertOk()->assertSee(trans('journey.anniversary.unsubscribed.title', [], 'ar'));
    expect($this->owner->fresh()->marketing_opt_out)->toBeTrue();

    // RFC 8058 one-click POST works without a session or CSRF token
    $this->owner->forceFill(['marketing_opt_out' => false])->save();
    $this->post($m[0])->assertOk();
    expect($this->owner->fresh()->marketing_opt_out)->toBeTrue()
        ->and((new AnniversaryEmail($this->event, 1))->via($this->owner->fresh()))->toBe([]);
});

it('mentions the album only while it is kept, and the loyalty discount', function () {
    $this->event->update(['storage_until' => now()->subDay()->toDateString()]);
    $mail = (new AnniversaryEmail($this->event->fresh(), 1))->toMail($this->owner);
    $lines = implode("\n", [...$mail->introLines, ...$mail->outroLines]);

    expect($lines)->not->toContain('/album')
        ->and($lines)->toContain('١٠٪');
});
