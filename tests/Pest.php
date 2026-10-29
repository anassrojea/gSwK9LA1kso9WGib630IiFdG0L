<?php

use App\Models\Event;
use App\Models\Household;
use App\Models\Invitation;
use Database\Factories\InvitationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Feature tests boot the app and run against the PostgreSQL test database
| (DB_DATABASE=daawa_test, see phpunit.xml). Unit tests are plain PHP.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * A published invitation with one household, ready for the guest routes.
 *
 * @return array{0: Event, 1: Household}
 */
function invitedHousehold(int $seats = 2, ?array $config = null): array
{
    $config ??= InvitationFactory::sampleConfig();

    $event = Event::factory()->create();
    Invitation::factory()->for($event)->create(['config' => $config]);
    $household = Household::factory()->for($event)->seats($seats)->create();

    return [$event, $household];
}

/**
 * Replace the rsvp section of a config (sections are a list, so they can't be merged by key).
 */
function withRsvpSection(array $rsvp, string $template = 'warda-rose'): array
{
    $config = InvitationFactory::sampleConfig($template);
    foreach ($config['sections'] as $i => $section) {
        if ($section['type'] === 'rsvp') {
            $config['sections'][$i] = ['type' => 'rsvp'] + $rsvp;
        }
    }

    return $config;
}

/**
 * The draft a POST /create response redirected to.
 */
function draftFrom($response): Event
{
    preg_match('#/create/([0-9a-f-]{36})/#', (string) $response->headers->get('Location'), $m);

    return Event::query()->with('invitation')->findOrFail($m[1] ?? null);
}

/**
 * A draft made in the wizard by the current test client (a guest unless actingAs was called).
 * Needs the catalog seeded.
 */
function wizardDraft(string $occasion = 'wedding'): Event
{
    return draftFrom(test()->post('/create', ['occasion' => $occasion]));
}
