<?php

use App\Http\Controllers\Auth\EmailCodeController;
use App\Models\Event;
use App\Models\LoginCode;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(fn () => Notification::fake());

/**
 * The code in the last email sent to $email.
 */
function sentCode(string $email): string
{
    $code = null;
    Notification::assertSentOnDemand(LoginCodeNotification::class, function ($n, $channels, $notifiable) use ($email, &$code) {
        if ($notifiable->routes['mail'] === $email) {
            $code = $n->code;
        }

        return $notifiable->routes['mail'] === $email;
    });

    return (string) $code;
}

function wrongCode(string $code): string
{
    return $code === '123456' ? '654321' : '123456';
}

it('sends a 6-digit code and stores only its hash', function () {
    User::factory()->create(['email' => 'huda@example.test']);

    $this->from('/login')->post('/login/code', ['email' => ' Huda@Example.test '])
        ->assertRedirect('/login')
        ->assertSessionHas(EmailCodeController::SESSION_KEY, 'huda@example.test');

    $code = sentCode('huda@example.test');
    $row = LoginCode::query()->sole();

    expect($code)->toMatch('/^\d{6}$/')
        ->and($row->purpose)->toBe('login')
        ->and($row->code_hash)->not->toContain($code)
        ->and($row->expires_at->diffInMinutes(now(), true))->toBeGreaterThan(9.9)->toBeLessThanOrEqual(10.0);

    $this->get('/login')->assertInertia(fn ($page) => $page->where('codeEmail', 'huda@example.test')->where('loginCodes', true));
});

it('answers the same whether or not the email has an account', function () {
    User::factory()->create(['email' => 'known@example.test']);

    $known = $this->from('/login')->post('/login/code', ['email' => 'known@example.test']);
    $this->flushSession();
    $unknown = $this->from('/login')->post('/login/code', ['email' => 'nobody@example.test']);

    expect($known->status())->toBe($unknown->status())
        ->and($known->headers->get('Location'))->toBe($unknown->headers->get('Location'))
        ->and(session('status'))->toBe(trans('app.auth.code.sent', ['email' => 'nobody@example.test']));
    Notification::assertSentOnDemandTimes(LoginCodeNotification::class, 2);
});

it('throttles sends per email and per IP address', function () {
    foreach (range(1, 3) as $i) {
        $this->post('/login/code', ['email' => 'a@example.test'])->assertSessionHasNoErrors();
    }
    $this->post('/login/code', ['email' => 'a@example.test'])->assertSessionHasErrors('email');

    // other addresses from the same IP, up to the IP's budget
    foreach (range(1, 7) as $i) {
        $this->post('/login/code', ['email' => "user{$i}@example.test"])->assertSessionHasNoErrors();
    }
    $this->post('/login/code', ['email' => 'one-more@example.test'])->assertSessionHasErrors('email');

    Notification::assertSentOnDemandTimes(LoginCodeNotification::class, 10);
});

it('invalidates the older code when a new one is sent', function () {
    $this->post('/login/code', ['email' => 'a@example.test']);
    $first = sentCode('a@example.test');
    RateLimiter::clear('login-code:ip:127.0.0.1');
    $this->post('/login/code', ['email' => 'a@example.test']);

    expect(LoginCode::query()->count())->toBe(1);

    if ($first !== sentCode('a@example.test')) {
        $this->post('/login/code/verify', ['email' => 'a@example.test', 'code' => $first])->assertSessionHasErrors('code');
        $this->assertGuest();
    }
});

it('signs in an existing user with the right code, once', function () {
    $user = User::factory()->unverified()->create(['email' => 'huda@example.test']);
    $this->post('/login/code', ['email' => 'huda@example.test']);
    $code = sentCode('huda@example.test');

    $this->post('/login/code/verify', ['email' => 'huda@example.test', 'code' => $code])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->email_verified_at)->not->toBeNull()
        ->and(User::query()->count())->toBe(1);

    // single use
    auth()->logout();
    $this->post('/login/code/verify', ['email' => 'huda@example.test', 'code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('accepts the code typed in Arabic-Indic digits', function () {
    User::factory()->create(['email' => 'huda@example.test']);
    $this->post('/login/code', ['email' => 'huda@example.test']);
    $arabic = strtr(sentCode('huda@example.test'), ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);

    $this->post('/login/code/verify', ['email' => 'huda@example.test', 'code' => $arabic])->assertRedirect('/dashboard');
    $this->assertAuthenticated();
});

it('rejects a wrong code and stops after 5 tries', function () {
    User::factory()->create(['email' => 'huda@example.test']);
    $this->post('/login/code', ['email' => 'huda@example.test']);
    $code = sentCode('huda@example.test');

    foreach (range(1, 4) as $i) {
        $this->post('/login/code/verify', ['email' => 'huda@example.test', 'code' => wrongCode($code)])
            ->assertSessionHasErrors(['code' => trans('app.auth.code.wrong')]);
    }
    $this->post('/login/code/verify', ['email' => 'huda@example.test', 'code' => wrongCode($code)])
        ->assertSessionHasErrors(['code' => trans('app.auth.code.too_many_attempts')]);

    // even the right code is refused now
    $this->post('/login/code/verify', ['email' => 'huda@example.test', 'code' => $code])
        ->assertSessionHasErrors(['code' => trans('app.auth.code.too_many_attempts')]);
    $this->assertGuest();
});

it('refuses an expired code', function () {
    User::factory()->create(['email' => 'huda@example.test']);
    $this->post('/login/code', ['email' => 'huda@example.test']);
    $code = sentCode('huda@example.test');

    $this->travel(11)->minutes();

    $this->post('/login/code/verify', ['email' => 'huda@example.test', 'code' => $code])
        ->assertSessionHasErrors(['code' => trans('app.auth.code.expired')]);
    $this->assertGuest();
});

it('does not let a code for one address sign in another', function () {
    User::factory()->create(['email' => 'a@example.test']);
    User::factory()->create(['email' => 'b@example.test']);
    $this->post('/login/code', ['email' => 'a@example.test']);

    $this->post('/login/code/verify', ['email' => 'b@example.test', 'code' => sentCode('a@example.test')])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('creates the account on first sign-in, with the name given or one from the email', function () {
    $this->post('/login/code', ['email' => 'lina@example.test']);
    $this->post('/login/code/verify', ['email' => 'lina@example.test', 'code' => sentCode('lina@example.test'), 'name' => 'لينا'])->assertRedirect('/dashboard');

    $lina = User::query()->where('email', 'lina@example.test')->sole();
    expect($lina)->name->toBe('لينا')->email_verified_at->not->toBeNull()->locale->toBe('ar')
        ->and($lina->hasPassword())->toBeFalse();
    $this->assertAuthenticatedAs($lina);

    auth()->logout();
    RateLimiter::clear('login-code:ip:127.0.0.1');
    $this->post('/login/code', ['email' => 'omar.khaled@example.test']);
    $this->post('/login/code/verify', ['email' => 'omar.khaled@example.test', 'code' => sentCode('omar.khaled@example.test')]);

    expect(User::query()->where('email', 'omar.khaled@example.test')->value('name'))->toBe('Omar Khaled');
});

it('attaches the visitor\'s guest drafts when signing in with a code', function () {
    $this->seed([PlanSeeder::class, CatalogSeeder::class]);
    $event = draftFrom($this->post('/create', ['occasion' => 'henna']));
    $this->get("/create/{$event->id}/review")->assertOk();

    $this->post('/login/code', ['email' => 'new@example.test']);
    $this->post('/login/code/verify', ['email' => 'new@example.test', 'code' => sentCode('new@example.test')])
        ->assertRedirect("/create/{$event->id}/review");

    $user = User::query()->where('email', 'new@example.test')->sole();
    expect(Event::query()->find($event->id))->owner_id->toBe($user->id)->anon_session_id->toBeNull();
});

it('can be switched off', function () {
    config(['account.login_codes.enabled' => false]);

    $this->post('/login/code', ['email' => 'a@example.test'])->assertNotFound();
    $this->get('/login')->assertInertia(fn ($page) => $page->where('loginCodes', false));
});
