<?php

use App\Models\Event;
use App\Models\User;
use App\Services\DraftAccess;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlanSeeder;

beforeEach(fn () => $this->seed([PlanSeeder::class, CatalogSeeder::class]));

function createDraft(string $occasion = 'wedding', array $extra = [])
{
    return test()->post('/create', ['occasion' => $occasion] + $extra);
}

it('shows the occasion picker to visitors without an account', function () {
    $this->get('/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('create/Occasion')->where('event', null)->has('occasions', count(Event::WIZARD_OCCASIONS)));
});

it('creates an anonymous draft for a guest', function () {
    $event = draftFrom(createDraft());

    expect($event->owner_id)->toBeNull()
        ->and($event->anon_session_id)->toHaveLength(48)
        ->and($event->status)->toBe('draft')
        ->and($event->occasion)->toBe('wedding')
        ->and($event->title)->toStartWith('زفاف ')
        ->and($event->invitation->published_at)->toBeNull()
        ->and($event->invitation->config['occasion'])->toBe('wedding')
        ->and($event->invitation->template_id)->not->toBeNull()
        ->and(session(DraftAccess::SESSION_KEY))->toBe([$event->id => $event->anon_session_id]);
});

it('creates a draft owned by a logged-in user', function () {
    $user = User::factory()->create();

    $event = draftFrom($this->actingAs($user)->post('/create', ['occasion' => 'engagement']));
    expect($event->owner_id)->toBe($user->id)
        ->and($event->anon_session_id)->toBeNull()
        ->and($event->invitation->config['occasion'])->toBe('engagement');

    $this->get("/create/{$event->id}/design")->assertOk();
});

it('starts from the chosen template and redirects to the design step', function () {
    $response = createDraft('wedding', ['template_id' => 'layali-envelope']);
    $event = draftFrom($response);

    $response->assertRedirect(route('create.step', [$event, 'design']));
    expect($event->invitation->template_id)->toBe('layali-envelope')
        ->and($event->invitation->config['opening']['id'])->toBe('envelope');
});

it('starts from the occasion\'s own template when there is one', function () {
    $event = draftFrom(createDraft('birthday'));

    expect($event->invitation->template_id)->toBe('birthday-gift')
        ->and($event->invitation->config['occasion'])->toBe('birthday');
});

it('adapts a template made for another occasion', function () {
    $event = draftFrom(createDraft('birthday', ['template_id' => 'warda-rose']));
    $config = $event->invitation->config;
    $names = collect($config['sections'])->firstWhere('type', 'names');

    expect($config['occasion'])->toBe('birthday')
        ->and($names)->toEqual(['type' => 'names', 'first' => 'سارة', 'age' => 7])
        ->and($event->title)->toBe('عيد ميلاد سارة');
});

it('rejects an unknown occasion or template', function () {
    createDraft('party')->assertSessionHasErrors('occasion');
    createDraft('wedding', ['template_id' => 'nope'])->assertSessionHasErrors('template_id');

    expect(Event::query()->count())->toBe(0);
});

it('throttles draft creation', function () {
    foreach (range(1, 10) as $i) {
        createDraft()->assertRedirect();
    }

    createDraft()->assertTooManyRequests();
});

it('lets the guest open every step of their own draft', function (string $step) {
    $event = draftFrom(createDraft());

    $this->get("/create/{$event->id}/{$step}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('create/'.ucfirst($step))->where('event.id', $event->id));
})->with(['occasion', 'design', 'details', 'guests', 'review']);

it('keeps guest drafts private to the browser session that made them', function () {
    $event = draftFrom(createDraft());
    $config = $event->invitation->config;

    // a different browser: same URL, no draft key in its session
    $this->flushSession();

    $this->get("/create/{$event->id}/details")->assertRedirect('/login');
    $this->get("/create/{$event->id}/preview")->assertRedirect('/login');
    $this->patchJson("/events/{$event->id}/config", ['config' => $config])->assertUnauthorized();
    $this->postJson("/events/{$event->id}/guests", ['name' => 'X', 'seats' => 1])->assertUnauthorized();

    $this->actingAs(User::factory()->create());
    $this->get("/create/{$event->id}/details")->assertForbidden();
    $this->patchJson("/events/{$event->id}/config", ['config' => $config])->assertForbidden();
});

it('does not accept a forged draft key', function () {
    $event = draftFrom(createDraft());

    $this->flushSession();
    $this->withSession([DraftAccess::SESSION_KEY => [$event->id => str_repeat('a', 48)]])
        ->get("/create/{$event->id}/details")
        ->assertRedirect('/login');
});

it('attaches guest drafts to the account on registration', function () {
    $event = draftFrom(createDraft());

    // visiting the review step makes registration return there
    $this->get("/create/{$event->id}/review")->assertOk();

    $this->post('/register', [
        'name' => 'Lina',
        'email' => 'lina@example.test',
        'password' => 'a-long-password-1',
        'password_confirmation' => 'a-long-password-1',
    ])->assertRedirect("/create/{$event->id}/review");

    $user = User::query()->where('email', 'lina@example.test')->firstOrFail();
    expect($event->fresh()->owner_id)->toBe($user->id)
        ->and($event->fresh()->anon_session_id)->toBeNull()
        ->and(session(DraftAccess::SESSION_KEY))->toBeNull();

    $this->get("/create/{$event->id}/review")->assertOk();
});

it('attaches guest drafts to the account on login, and only this browser\'s drafts', function () {
    $user = User::factory()->create(['password' => 'password']);

    $someoneElses = draftFrom(createDraft());
    $this->flushSession();

    $mine = draftFrom(createDraft('henna'));

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();

    expect($mine->fresh()->owner_id)->toBe($user->id)
        ->and($someoneElses->fresh()->owner_id)->toBeNull()
        ->and($someoneElses->fresh()->anon_session_id)->not->toBeNull();
});
