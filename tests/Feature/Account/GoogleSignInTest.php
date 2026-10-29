<?php

use App\Models\Event;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlanSeeder;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;

/*
| Google is never called: Socialite's driver is mocked (plan: no real calls to Google).
*/

beforeEach(function () {
    config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret']);
});

function googleUser(string $id = 'google-123', ?string $email = 'sara@example.test', bool $verified = true, string $name = 'Sara Ahmad'): GoogleUser
{
    return (new GoogleUser)
        ->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $verified, 'name' => $name])
        ->map(['id' => $id, 'name' => $name, 'email' => $email]);
}

function fakeGoogle(GoogleUser|Throwable $result): void
{
    $mock = Socialite::shouldReceive('driver->user');
    $result instanceof Throwable ? $mock->andThrow($result) : $mock->andReturn($result);
}

it('hides Google when it is not configured, and shows it when it is', function () {
    config(['services.google.client_id' => null]);
    $this->get('/login')->assertInertia(fn ($page) => $page->where('auth.providers.google', false));
    $this->get('/auth/google/redirect')->assertNotFound();

    config(['services.google.client_id' => 'test-client']);
    $this->get('/login')->assertInertia(fn ($page) => $page->where('auth.providers.google', true));
    $this->get('/register')->assertInertia(fn ($page) => $page->where('auth.providers.google', true));
});

it('sends the visitor to Google', function () {
    Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth?client_id=test-client'));

    $this->get('/auth/google/redirect')->assertRedirect('https://accounts.google.com/o/oauth2/auth?client_id=test-client');
});

it('creates a verified account for a new Google user, in the visitor\'s language', function () {
    fakeGoogle(googleUser());

    $this->withSession(['locale' => 'en'])->get('/auth/google/callback?state=x&code=y')->assertRedirect('/dashboard');

    $user = User::query()->where('email', 'sara@example.test')->firstOrFail();
    expect($user)
        ->google_id->toBe('google-123')
        ->name->toBe('Sara Ahmad')
        ->locale->toBe('en')
        ->email_verified_at->not->toBeNull()
        ->and($user->hasPassword())->toBeFalse();
    $this->assertAuthenticatedAs($user);
});

it('signs in the user who already has this google_id', function () {
    $user = User::factory()->create(['google_id' => 'google-123', 'email' => 'other@example.test']);
    fakeGoogle(googleUser(email: 'changed@example.test'));

    $this->get('/auth/google/callback')->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
    expect(User::query()->count())->toBe(1);
});

it('links Google to an existing account with the same verified email', function () {
    $user = User::factory()->unverified()->create(['email' => 'sara@example.test']);
    fakeGoogle(googleUser(email: 'Sara@Example.test'));

    $this->get('/auth/google/callback')->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh())->google_id->toBe('google-123')->email_verified_at->not->toBeNull();
});

it('refuses to link an email Google has not verified', function () {
    $user = User::factory()->create(['email' => 'sara@example.test']);
    fakeGoogle(googleUser(verified: false));

    $this->get('/auth/google/callback')
        ->assertRedirect('/login')
        ->assertSessionHas('warning', trans('app.auth.google.errors.unverified'));

    $this->assertGuest();
    expect($user->fresh()->google_id)->toBeNull();
});

it('handles a cancelled consent screen and an expired state', function () {
    $this->get('/auth/google/callback?error=access_denied')
        ->assertRedirect('/login')
        ->assertSessionHas('warning', trans('app.auth.google.errors.cancelled'));

    fakeGoogle(new InvalidStateException);
    $this->get('/auth/google/callback?state=stale&code=y')
        ->assertRedirect('/login')
        ->assertSessionHas('warning', trans('app.auth.google.errors.state'));

    $this->assertGuest();
});

it('attaches the visitor\'s guest drafts on Google sign-in', function () {
    $this->seed([PlanSeeder::class, CatalogSeeder::class]);
    $event = draftFrom($this->post('/create', ['occasion' => 'wedding']));
    $this->get("/create/{$event->id}/review")->assertOk();

    fakeGoogle(googleUser());
    $this->get('/auth/google/callback')->assertRedirect("/create/{$event->id}/review");

    $user = User::query()->where('email', 'sara@example.test')->firstOrFail();
    expect(Event::query()->find($event->id))->owner_id->toBe($user->id)->anon_session_id->toBeNull();
});

it('links Google from the security tab, and refuses a Google account used by someone else', function () {
    $user = User::factory()->create();
    User::factory()->create(['google_id' => 'taken-id']);
    Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));

    // two round trips to Google: first a Google account someone else uses, then the user's own
    Socialite::shouldReceive('driver->user')->andReturn(googleUser(id: 'taken-id', email: $user->email), googleUser(id: 'mine', email: 'a-different-address@example.test'));

    $this->actingAs($user)->get('/auth/google/redirect');
    $this->actingAs($user)->get('/auth/google/callback')
        ->assertRedirect('/account/security')
        ->assertSessionHas('warning', trans('app.auth.google.errors.taken'));
    expect($user->fresh()->google_id)->toBeNull();

    $this->actingAs($user)->get('/auth/google/redirect');
    $this->actingAs($user)->get('/auth/google/callback')->assertRedirect('/account/security');
    expect($user->fresh()->google_id)->toBe('mine');
});

it('never unlinks Google when it is the only way to sign in', function () {
    config(['account.login_codes.enabled' => false]);
    $user = User::factory()->create(['password' => null, 'google_id' => 'google-123']);

    expect($user->canUnlinkGoogle())->toBeFalse();
    $this->actingAs($user)->delete('/account/google')->assertSessionHas('warning', trans('app.account.security.google_only_method'));
    expect($user->fresh()->google_id)->toBe('google-123');

    // with a password (or email codes) there is another way in
    $user->forceFill(['password' => 'a-password-123'])->save();
    $this->actingAs($user->fresh())->delete('/account/google')->assertSessionHas('status');
    expect($user->fresh()->google_id)->toBeNull();
});

it('counts email codes as a way in when they are on', function () {
    config(['account.login_codes.enabled' => true]);
    $user = User::factory()->create(['password' => null, 'google_id' => 'google-123']);

    expect($user->signInMethods())->toBe(['google', 'email_code'])
        ->and($user->canUnlinkGoogle())->toBeTrue();
});
