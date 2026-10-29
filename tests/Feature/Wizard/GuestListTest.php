<?php

use App\Models\Household;
use App\Models\User;
use App\Services\Wizard\GuestListParser;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlanSeeder;

beforeEach(fn () => $this->seed([PlanSeeder::class, CatalogSeeder::class]));

function guestFixture(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/guest-list.json')), true);
}

it('reads pasted lines (same fixture as the JS preview)', function () {
    foreach (guestFixture()['lines'] as [$line, $name, $seats]) {
        expect(GuestListParser::parseLine($line))->toBe(['name' => $name, 'seats' => $seats], $line);
    }
    foreach (guestFixture()['skipped'] as $line) {
        expect(GuestListParser::parseLine($line))->toBeNull();
    }
});

it('imports a pasted list as households, one seat by default', function () {
    $event = wizardDraft();

    $this->post("/events/{$event->id}/guests/import", ['text' => "أم فيصل - ٣\nKhaled Ali, 2\n\nخالة نورة\r\nعائلة الزين: 4"])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $households = $event->households()->orderBy('created_at')->orderBy('display_name')->get();
    expect($households->pluck('seats_allowed', 'display_name')->all())->toEqual([
        'أم فيصل' => 3, 'Khaled Ali' => 2, 'خالة نورة' => 1, 'عائلة الزين' => 4,
    ])->and($households->pluck('token')->filter(fn ($t) => strlen($t) === Household::TOKEN_LENGTH))->toHaveCount(4);
});

it('refuses an empty paste', function () {
    $event = wizardDraft();

    $this->post("/events/{$event->id}/guests/import", ['text' => "\n  \n -"])->assertSessionHasErrors('text');
    expect($event->households()->count())->toBe(0);
});

it('keeps the free plan\'s soft guest limit with a friendly message', function () {
    $event = wizardDraft();

    $this->post("/events/{$event->id}/guests/import", ['text' => "A - 30\nB - 25"])
        ->assertSessionHasErrors(['text' => 'باقتك تتسع لـ ٥٠ ضيفاً والمتبقي ٥٠ مقعداً. الباقات الأكبر تأتي مع الدفع.']);
    expect($event->households()->count())->toBe(0);

    $this->post("/events/{$event->id}/guests/import", ['text' => "A - 30\nB - 20"])->assertSessionHasNoErrors();

    $this->post("/events/{$event->id}/guests", ['name' => 'C', 'seats' => 1])->assertSessionHasErrors('name');

    $household = $event->households()->where('display_name', 'B')->first();
    $this->patch("/households/{$household->id}", ['name' => 'B', 'seats' => 21])->assertSessionHasErrors('seats');
    $this->patch("/households/{$household->id}", ['name' => 'Family B', 'seats' => 19])->assertSessionHasNoErrors();
    expect($household->fresh()->only('display_name', 'seats_allowed'))->toBe(['display_name' => 'Family B', 'seats_allowed' => 19]);

    $this->post("/events/{$event->id}/guests", ['name' => 'C', 'seats' => 1])->assertSessionHasNoErrors();
    expect($event->households()->sum('seats_allowed'))->toBe(50);
});

it('adds, edits and deletes one household', function () {
    $event = wizardDraft();

    $this->post("/events/{$event->id}/guests", ['name' => 'Umm Faisal', 'seats' => 3])->assertSessionHasNoErrors();
    $household = $event->households()->firstOrFail();

    $this->post("/events/{$event->id}/guests", ['name' => '', 'seats' => 0])->assertSessionHasErrors(['name', 'seats']);

    $this->delete("/households/{$household->id}")->assertRedirect();
    expect($event->households()->count())->toBe(0);
});

it('does not let another session or user touch the guest list', function () {
    $event = wizardDraft();
    $this->post("/events/{$event->id}/guests", ['name' => 'Umm Faisal', 'seats' => 3]);
    $household = $event->households()->firstOrFail();

    $this->flushSession();
    $this->patchJson("/households/{$household->id}", ['name' => 'X', 'seats' => 1])->assertUnauthorized();
    $this->deleteJson("/households/{$household->id}")->assertUnauthorized();
    $this->postJson("/events/{$event->id}/guests/import", ['text' => 'X'])->assertUnauthorized();

    $this->actingAs(User::factory()->create());
    $this->patchJson("/households/{$household->id}", ['name' => 'X', 'seats' => 1])->assertForbidden();

    expect($household->fresh()->display_name)->toBe('Umm Faisal');
});
