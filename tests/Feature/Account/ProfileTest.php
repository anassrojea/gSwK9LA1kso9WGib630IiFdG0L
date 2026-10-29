<?php

use App\Models\LoginCode;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use Illuminate\Support\Facades\Notification;

function profileInput(User $user, array $overrides = []): array
{
    return $overrides + [
        'name' => $user->name,
        'email' => $user->email,
        'phone' => '',
        'locale' => $user->locale,
        'country' => '',
        'currency' => '',
    ];
}

it('needs an account for every tab', function (string $path) {
    $this->get($path)->assertRedirect('/login');
})->with(['/account', '/account/security', '/account/billing', '/account/privacy']);

it('renders each tab with its own data', function (string $path, string $tab, string $prop) {
    $user = User::factory()->create();

    $this->actingAs($user)->get($path)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('account/Show')->where('tab', $tab)->has($prop)->where('account.name', $user->name));
})->with([
    ['/account', 'profile', 'profile'],
    ['/account/security', 'security', 'security'],
    ['/account/billing', 'billing', 'billing'],
    ['/account/privacy', 'privacy', 'privacy'],
]);

it('updates the name, phone, language, country and currency', function () {
    $user = User::factory()->create(['locale' => 'ar']);

    $this->actingAs($user)
        ->put('/account/profile', profileInput($user, [
            'name' => 'Huda Saleh',
            'phone' => '٠٠٩٦٦ ٥٥ ١٢٣ ٤٥٦٧',
            'locale' => 'en',
            'country' => 'SA',
            'currency' => 'SAR',
        ]))
        ->assertRedirect('/account')
        ->assertSessionHas('locale', 'en');

    expect($user->fresh())
        ->name->toBe('Huda Saleh')
        ->phone->toBe('+966551234567')
        ->locale->toBe('en')
        ->country->toBe('SA')
        ->currency->toBe('SAR');
});

it('treats "another country" and "from my country" as no default', function () {
    $user = User::factory()->create(['country' => 'SY', 'currency' => 'SYP']);

    $this->actingAs($user)->put('/account/profile', profileInput($user, ['country' => 'other', 'currency' => '']))->assertSessionHasNoErrors();

    expect($user->fresh())->country->toBeNull()->currency->toBeNull();
});

it('validates the profile', function (array $input, string $field) {
    $user = User::factory()->create();
    User::factory()->create(['email' => 'taken@example.test', 'phone' => '+963912345678']);

    $this->actingAs($user)->put('/account/profile', profileInput($user, $input))->assertSessionHasErrors($field);
})->with([
    'no name' => [['name' => ''], 'name'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
    'email taken' => [['email' => 'taken@example.test', 'current_password' => 'password'], 'email'],
    'phone without country code' => [['phone' => '0912345678'], 'phone'],
    'phone too short' => [['phone' => '+12'], 'phone'],
    'phone taken' => [['phone' => '+963 912 345 678'], 'phone'],
    'unknown language' => [['locale' => 'fr'], 'locale'],
    'unknown country' => [['country' => 'XX'], 'country'],
    'unknown currency' => [['currency' => 'EUR'], 'currency'],
]);

it('needs the current password to change the email', function () {
    $user = User::factory()->create(['email' => 'old@example.test']);

    $this->actingAs($user)->put('/account/profile', profileInput($user, ['email' => 'new@example.test']))
        ->assertSessionHasErrors('current_password');
    $this->actingAs($user)->put('/account/profile', profileInput($user, ['email' => 'new@example.test', 'current_password' => 'wrong-one']))
        ->assertSessionHasErrors('current_password');
    expect($user->fresh()->email)->toBe('old@example.test');

    $this->actingAs($user)->put('/account/profile', profileInput($user, ['email' => 'New@Example.test', 'current_password' => 'password']))
        ->assertSessionHasNoErrors();

    // not proven yet: the address is marked unverified
    expect($user->fresh())->email->toBe('new@example.test')->email_verified_at->toBeNull();
});

it('does not ask for a password when the email stays the same', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put('/account/profile', profileInput($user, ['name' => 'Same Email']))->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe('Same Email');
});

it('confirms a new email with a code sent there when the account has no password', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'g@example.test', 'password' => null, 'google_id' => 'g-1']);

    $this->actingAs($user)->put('/account/profile', profileInput($user, ['email' => 'new@example.test']))
        ->assertSessionHasErrors('email_code');

    $this->actingAs($user)->post('/account/email-code', ['email' => 'new@example.test'])->assertSessionHasNoErrors();

    $code = null;
    Notification::assertSentOnDemand(LoginCodeNotification::class, function ($n, $channels, $notifiable) use (&$code) {
        $code = $n->code;

        return $notifiable->routes['mail'] === 'new@example.test' && $n->purpose === LoginCode::EMAIL;
    });

    $this->actingAs($user)->put('/account/profile', profileInput($user, ['email' => 'new@example.test', 'email_code' => $code === '000000' ? '111111' : '000000']))
        ->assertSessionHasErrors('email_code');
    $this->actingAs($user)->put('/account/profile', profileInput($user, ['email' => 'new@example.test', 'email_code' => $code]))
        ->assertSessionHasNoErrors();

    expect($user->fresh())->email->toBe('new@example.test')->email_verified_at->not->toBeNull();
});
