<?php

use App\Models\Reminder;
use Tests\Support\DashboardFixtures;

it('picks the families to remind', function (string $audience, array $expected) {
    [$event, $h] = DashboardFixtures::event();

    $guests = $this->actingAs($event->owner)
        ->postJson("/dashboard/events/{$event->id}/reminders", ['audience' => $audience])
        ->assertOk()
        ->json('guests');

    expect(collect($guests)->pluck('household_id')->sort()->values()->all())
        ->toBe(collect($expected)->map(fn ($key) => $h[$key]->id)->sort()->values()->all());
})->with([
    'never opened' => ['never', ['not_opened']],
    'opened, no reply' => ['opened', ['opened']],
    'both' => ['both', ['not_opened', 'opened']],
]);

it('builds a personal wa.me reminder per family and records a reminder row each', function () {
    [$event, $h] = DashboardFixtures::event();

    $guests = collect($this->actingAs($event->owner)
        ->postJson("/dashboard/events/{$event->id}/reminders", ['audience' => 'both'])
        ->json('guests'))->keyBy('household_id');

    $withPhone = $guests[$h['not_opened']->id];
    expect($withPhone['whatsapp_url'])->toStartWith('https://wa.me/962791111111?text=')
        ->and(rawurldecode($withPhone['whatsapp_url']))->toContain($h['not_opened']->display_name)
        ->and(rawurldecode($withPhone['whatsapp_url']))->toContain('/i/'.$h['not_opened']->token)
        ->and($guests[$h['opened']->id]['whatsapp_url'])->toStartWith('https://wa.me/?text=');

    $rows = Reminder::query()->where('event_id', $event->id)->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('channel')->unique()->all())->toBe(['whatsapp'])
        ->and($rows->pluck('status')->unique()->all())->toBe(['queued']);

    // tapping a family marks its reminder sent
    $this->postJson("/dashboard/reminders/{$withPhone['reminder_id']}/sent")->assertOk()->assertJson(['status' => 'sent']);
    $sent = Reminder::query()->find($withPhone['reminder_id']);
    expect($sent->status)->toBe('sent')->and($sent->sent_at)->not->toBeNull();

    // opening the list again reuses the untapped reminder and starts a new one for the sent family
    $again = collect($this->postJson("/dashboard/events/{$event->id}/reminders", ['audience' => 'both'])->json('guests'))->keyBy('household_id');
    expect($again[$h['opened']->id]['reminder_id'])->toBe($guests[$h['opened']->id]['reminder_id'])
        ->and($again[$h['not_opened']->id]['reminder_id'])->not->toBe($withPhone['reminder_id'])
        ->and($again[$h['not_opened']->id]['last_reminded_at'])->not->toBeNull()
        ->and(Reminder::query()->where('event_id', $event->id)->count())->toBe(3);
});

it('validates the audience', function () {
    [$event] = DashboardFixtures::event();

    $this->actingAs($event->owner)->postJson("/dashboard/events/{$event->id}/reminders", ['audience' => 'everyone'])->assertUnprocessable();
});
