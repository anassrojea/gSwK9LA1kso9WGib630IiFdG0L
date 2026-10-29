<?php

use App\Models\Checkin;
use App\Models\Household;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\Support\DashboardFixtures;

/**
 * Every dashboard endpoint, as [method, url, data].
 *
 * @return list<array{0: string, 1: string, 2: array}>
 */
function dashboardEndpoints(): array
{
    [$event, $h] = DashboardFixtures::event();
    $reminder = Reminder::query()->create(['event_id' => $event->id, 'household_id' => $h['opened']->id, 'channel' => 'whatsapp', 'status' => 'queued']);
    $file = fn () => UploadedFile::fake()->createWithContent('g.csv', "name,seats\nA,1\n");

    return [
        ['get', "/dashboard/events/{$event->id}", []],
        ['patch', "/dashboard/events/{$event->id}/messages", ['invite' => 'x']],
        ['get', "/dashboard/events/{$event->id}/guests.csv", []],
        ['post', "/dashboard/events/{$event->id}/guests/csv/preview", ['file' => $file()]],
        ['post', "/dashboard/events/{$event->id}/guests/csv", ['file' => $file()]],
        ['post', "/dashboard/events/{$event->id}/reminders", ['audience' => 'both']],
        ['post', "/dashboard/reminders/{$reminder->id}/sent", []],
        ['patch', "/dashboard/households/{$h['opened']->id}", ['name' => 'x', 'seats' => 1]],
        ['delete', "/dashboard/households/{$h['opened']->id}", []],
        ['post', "/dashboard/households/{$h['opened']->id}/checkin", []],
        ['post', "/events/{$event->id}/guests/import", ['text' => 'A - 1']], // the wizard's paste endpoint the dashboard reuses
    ];
}

it('gives a stranger 403 on every dashboard endpoint and changes nothing', function () {
    $endpoints = dashboardEndpoints();
    $this->actingAs(User::factory()->create());

    foreach ($endpoints as [$method, $url, $data]) {
        $this->{$method}($url, $data, ['Accept' => 'application/json'])->assertForbidden();
    }

    expect(Checkin::query()->count())->toBe(0)
        ->and(Reminder::query()->where('status', 'sent')->count())->toBe(0)
        ->and(Household::query()->count())->toBe(5);
});

it('asks logged-out visitors to log in', function () {
    foreach (dashboardEndpoints() as [$method, $url, $data]) {
        $response = $this->{$method}($url, $data);
        expect($response->status())->toBeIn([302, 401], "{$method} {$url}");
        if ($response->status() === 302) {
            $response->assertRedirect('/login');
        }
    }
});

it('returns 404 for ids that are not UUIDs', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard/events/not-a-uuid')->assertNotFound();
    $this->post('/dashboard/reminders/123/sent')->assertNotFound();
});
