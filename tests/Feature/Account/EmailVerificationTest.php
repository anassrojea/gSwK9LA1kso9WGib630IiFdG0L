<?php

use App\Models\Order;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use App\Services\Account\EmailVerification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\Support\Payments;

/*
| Phase 6B: password sign-ups verify their email before paying or publishing.
*/

beforeEach(function () {
    Payments::setUp();
    Notification::fake();
});

function registerLina(): User
{
    test()->post('/register', ['name' => 'Lina', 'email' => 'lina@example.test', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'])
        ->assertRedirect('/dashboard');

    return User::query()->where('email', 'lina@example.test')->sole();
}

function verificationUrl(User $user): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]);
}

it('sends a localized verification link to a password sign-up', function () {
    $user = registerLina();

    expect($user->hasVerifiedEmail())->toBeFalse()
        ->and(EmailVerification::required($user))->toBeTrue();

    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user) {
        $mail = $notification->toMail($user);

        return str_contains((string) $mail->actionUrl, '/email/verify/'.$user->id.'/')
            && $mail->subject === trans('app.verify.mail.subject', [], 'ar');
    });
});

it('shows the verify-email page and shares the flag with every page', function () {
    registerLina();

    $this->get('/email/verify')->assertOk()->assertInertia(fn ($page) => $page
        ->component('auth/VerifyEmail')
        ->where('email', 'lina@example.test')
        ->where('auth.user.email_verified', false)
        ->has('translations.verify.resend'));

    $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('auth.user.email_verified', false));
});

it('blocks checkout and publishing until the email is verified, but not drafts', function () {
    $user = registerLina();
    $event = Payments::event($user);

    // drafts stay editable
    $this->patch("/events/{$event->id}/config", ['config' => $event->invitation->config])->assertSuccessful();

    $this->from("/create/{$event->id}/review")
        ->post('/checkout', ['event_id' => $event->id, 'plan' => 'premium', 'currency' => 'SAR', 'gateway' => 'transfer', 'from' => 'wizard'])
        ->assertRedirect("/create/{$event->id}/review")
        ->assertSessionHasErrors([EmailVerification::ERROR => trans('app.verify.blocked.checkout', ['email' => 'lina@example.test'], 'ar')]);
    expect(Order::query()->count())->toBe(0);

    $this->postJson('/checkout', ['event_id' => $event->id, 'plan' => 'premium', 'currency' => 'SAR', 'gateway' => 'transfer'])
        ->assertForbidden()
        ->assertJsonPath('errors.verify_email.0', trans('app.verify.blocked.checkout', ['email' => 'lina@example.test'], 'ar'));

    $this->post("/create/{$event->id}/publish")->assertSessionHasErrors(EmailVerification::ERROR);
    expect($event->invitation->fresh()->published_at)->toBeNull();

    // following the link lifts the gate
    $this->get(verificationUrl($user))->assertRedirect();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->from("/create/{$event->id}/review")
        ->post('/checkout', ['event_id' => $event->id, 'plan' => 'premium', 'currency' => 'SAR', 'gateway' => 'transfer', 'from' => 'wizard'])
        ->assertSessionHasNoErrors();
    expect(Order::query()->count())->toBe(1);
});

it('throttles resending the link to 3 per 10 minutes', function () {
    $user = registerLina();
    Notification::fake();

    foreach (range(1, 3) as $i) {
        $this->postJson('/email/verification-notification')->assertStatus(202);
    }
    $this->postJson('/email/verification-notification')->assertStatus(429);

    Notification::assertSentToTimes($user, VerifyEmail::class, 3);

    $this->travel(11)->minutes();
    $this->postJson('/email/verification-notification')->assertStatus(202);
});

it('treats Google sign-ins as verified', function () {
    config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret']);
    Socialite::shouldReceive('driver->user')->andReturn((new GoogleUser)
        ->setRaw(['sub' => 'g-1', 'email' => 'sara@example.test', 'email_verified' => true, 'name' => 'Sara'])
        ->map(['id' => 'g-1', 'name' => 'Sara', 'email' => 'sara@example.test']));

    $this->get('/auth/google/callback?state=x&code=y')->assertRedirect('/dashboard');

    $user = User::query()->where('email', 'sara@example.test')->sole();
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and(EmailVerification::required($user))->toBeFalse();
    Notification::assertNotSentTo($user, VerifyEmail::class);
});

it('treats email-code sign-ins as verified, even for an older unverified account', function () {
    $user = User::factory()->unverified()->create(['email' => 'huda@example.test']);

    $this->post('/login/code', ['email' => 'huda@example.test']);
    $code = null;
    Notification::assertSentOnDemand(LoginCodeNotification::class, function ($n) use (&$code) {
        $code = $n->code;

        return true;
    });
    $this->post('/login/code/verify', ['email' => 'huda@example.test', 'code' => $code])->assertRedirect();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('makes a changed email unverified again and sends a new link', function () {
    $user = User::factory()->create(['email' => 'old@example.test']);
    $event = Payments::event($user);

    $this->actingAs($user)->put('/account/profile', ['name' => $user->name, 'email' => 'new@example.test', 'current_password' => 'password'])
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->email)->toBe('new@example.test')
        ->and($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, VerifyEmail::class);

    $this->post('/checkout', ['event_id' => $event->id, 'plan' => 'premium', 'currency' => 'SAR', 'gateway' => 'transfer'])
        ->assertSessionHasErrors(EmailVerification::ERROR);
});

it('keeps unverified admins out of Filament until they verify', function () {
    $admin = User::factory()->admin()->unverified()->create();

    $this->actingAs($admin)->get('/admin')->assertRedirect('/admin/email-verification/prompt');

    $admin->markEmailAsVerified();
    $this->actingAs($admin->fresh())->get('/admin')->assertOk();
});
