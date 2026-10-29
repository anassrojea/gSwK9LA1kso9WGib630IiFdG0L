<?php

use App\Models\User;
use App\Services\Account\SessionRegistry;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;

it('changes the password with the current one', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put('/account/password', [
        'current_password' => 'wrong',
        'password' => 'a-new-password-1',
        'password_confirmation' => 'a-new-password-1',
    ])->assertSessionHasErrors('current_password');

    $this->actingAs($user)->put('/account/password', [
        'current_password' => 'password',
        'password' => 'a-new-password-1',
        'password_confirmation' => 'something-else',
    ])->assertSessionHasErrors('password');

    $this->actingAs($user)->put('/account/password', [
        'current_password' => 'password',
        'password' => 'a-new-password-1',
        'password_confirmation' => 'a-new-password-1',
    ])->assertSessionHasNoErrors()->assertSessionHas('status');

    expect(Hash::check('a-new-password-1', $user->fresh()->password))->toBeTrue();
});

it('lets an account without a password set one, then sign in with it', function () {
    $user = User::factory()->create(['password' => null, 'google_id' => 'g-2']);
    expect($user->hasPassword())->toBeFalse();

    $this->actingAs($user)->put('/account/password', [
        'password' => 'first-password-9',
        'password_confirmation' => 'first-password-9',
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->hasPassword())->toBeTrue()
        ->and($user->fresh()->signInMethods())->toContain('password');

    auth()->logout();
    $this->post('/login', ['email' => $user->email, 'password' => 'first-password-9'])->assertRedirect();
    $this->assertAuthenticatedAs($user->fresh());
});

it('shows the sign-in methods on the security tab', function () {
    config(['services.google.client_id' => 'test-client']);
    $user = User::factory()->create(['google_id' => 'g-3']);

    $this->actingAs($user)->get('/account/security')
        ->assertInertia(fn ($page) => $page
            ->where('security.methods', ['password', 'google', 'email_code'])
            ->where('security.google.linked', true)
            ->where('security.can_unlink_google', true));
});

it('lists signed-in devices and signs the others out', function () {
    $user = User::factory()->create(['remember_token' => 'old-token']);
    $registry = app(SessionRegistry::class);

    // another device: a session in the handler plus its entry in the registry
    $other = str_repeat('b', 40);
    Session::getHandler()->write($other, serialize(['_token' => 'x']));
    $request = Request::create('/dashboard', 'GET', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1']);
    $request->setLaravelSession(new Store('test', Session::getHandler(), $other));
    $registry->touch($user, $request);

    $this->actingAs($user)->get('/account/security')
        ->assertInertia(fn ($page) => $page
            ->has('security.sessions', 2)
            ->where('security.sessions.0.current', true)
            ->where('security.sessions.1.key', SessionRegistry::key($other))
            ->where('security.sessions.1.current', false));

    $this->actingAs($user)->delete('/account/sessions')->assertSessionHas('status');

    expect(Session::getHandler()->read($other))->toBe('')
        ->and($user->fresh()->remember_token)->not->toBe('old-token');

    $this->actingAs($user)->get('/account/security')->assertInertia(fn ($page) => $page->where(
        'security.sessions',
        fn ($sessions) => collect($sessions)->pluck('key')->doesntContain(SessionRegistry::key($other)),
    ));
});

it('never shows raw session ids to the page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/account/security');
    $sessionId = session()->getId();

    expect(json_encode($response->viewData('page')))->not->toContain($sessionId);
});
