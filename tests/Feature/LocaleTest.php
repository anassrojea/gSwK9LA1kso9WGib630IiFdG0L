<?php

use App\Models\User;

it('defaults to Arabic, right to left', function () {
    $this->get('/')->assertOk()->assertSee('lang="ar" dir="rtl"', false);
});

it('switches to English, left to right', function () {
    $this->get('/?lang=en')->assertOk()->assertSee('lang="en" dir="ltr"', false);
});

it('remembers the chosen locale in the session and on the user', function () {
    $user = User::factory()->create(['locale' => 'ar']);

    $this->actingAs($user)->get('/locale/en')->assertRedirect();
    $this->get('/')->assertSee('dir="ltr"', false);

    expect($user->refresh()->locale)->toBe('en');
});

it('ignores unsupported locales', function () {
    $this->get('/locale/fr')->assertNotFound();
    $this->get('/?lang=fr')->assertSee('dir="rtl"', false);
});

it('shares locale and direction with Inertia pages', function () {
    $this->actingAs(User::factory()->create(['locale' => 'en']))
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('locale', 'en')->where('dir', 'ltr')->has('translations.dashboard'));
});

it('renders the invitation in its own language regardless of the visitor', function () {
    [, $household] = invitedHousehold();

    $this->get("/i/{$household->token}?lang=en", ['Accept-Language' => 'en'])->assertSee('dir="rtl"', false);
});
