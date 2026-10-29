<?php

use App\Models\Rsvp;

it('accepts a reply within the seat limit', function () {
    [, $household] = invitedHousehold(seats: 3);

    $this->postJson("/i/{$household->token}/rsvp", [
        'attending' => true,
        'people_count' => 3,
        'kids_count' => 1,
        'message' => 'ألف مبروك',
    ])
        ->assertOk()
        ->assertJsonPath('household.seats_allowed', 3)
        ->assertJsonPath('rsvp.attending', true)
        ->assertJsonPath('rsvp.people_count', 3);

    expect($household->rsvp()->first())
        ->people_count->toBe(3)
        ->kids_count->toBe(1)
        ->message->toBe('ألف مبروك');
});

it('cannot exceed seats_allowed', function () {
    [, $household] = invitedHousehold(seats: 2);

    $this->postJson("/i/{$household->token}/rsvp", ['attending' => true, 'people_count' => 3])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('people_count');

    expect(Rsvp::query()->count())->toBe(0);
});

it('cannot exceed seats_allowed when changing an existing reply', function () {
    [, $household] = invitedHousehold(seats: 2);
    $this->postJson("/i/{$household->token}/rsvp", ['attending' => true, 'people_count' => 2])->assertOk();

    $this->postJson("/i/{$household->token}/rsvp", ['attending' => true, 'people_count' => 5])
        ->assertUnprocessable();

    expect($household->rsvp()->value('people_count'))->toBe(2);
});

it('requires at least one person when attending', function () {
    [, $household] = invitedHousehold();

    $this->postJson("/i/{$household->token}/rsvp", ['attending' => true, 'people_count' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('people_count');
});

it('does not allow more kids than people', function () {
    [, $household] = invitedHousehold(seats: 4);

    $this->postJson("/i/{$household->token}/rsvp", ['attending' => true, 'people_count' => 2, 'kids_count' => 3])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('kids_count');
});

it('stores a declined reply with nobody attending', function () {
    [, $household] = invitedHousehold(seats: 4);

    $this->postJson("/i/{$household->token}/rsvp", ['attending' => false, 'people_count' => 9])
        ->assertOk()
        ->assertJsonPath('rsvp.attending', false)
        ->assertJsonPath('rsvp.people_count', 0);
});

it('keeps one reply per household when the guest changes their answer', function () {
    [, $household] = invitedHousehold(seats: 2);

    $this->postJson("/i/{$household->token}/rsvp", ['attending' => true, 'people_count' => 2])->assertOk();
    $this->postJson("/i/{$household->token}/rsvp", ['attending' => false])->assertOk();

    expect(Rsvp::query()->where('household_id', $household->id)->count())->toBe(1)
        ->and($household->rsvp()->value('attending'))->toBeFalse();
});

it('rejects replies after the RSVP cutoff', function () {
    [, $household] = invitedHousehold(config: withRsvpSection(['askMessage' => true, 'cutoff' => now()->subDay()->toIso8601String()]));

    $this->postJson("/i/{$household->token}/rsvp", ['attending' => true, 'people_count' => 1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('attending');

    $this->getJson("/i/{$household->token}/state")->assertJsonPath('rsvp_open', false);
});

it('refuses a message when the host did not ask for one', function () {
    [, $household] = invitedHousehold(config: withRsvpSection(['askMessage' => false]));

    $this->postJson("/i/{$household->token}/rsvp", ['attending' => true, 'people_count' => 1, 'message' => 'hi'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('message');
});

it('answers validation errors in the invitation language', function () {
    [, $household] = invitedHousehold(seats: 1);

    $this->postJson("/i/{$household->token}/rsvp", ['attending' => true, 'people_count' => 2])
        ->assertJsonPath('errors.people_count.0', 'خُصّص لكم مقعد واحد فقط.');
});

it('rate-limits replies', function () {
    [, $household] = invitedHousehold(seats: 2);

    foreach (range(1, 10) as $i) {
        $this->postJson("/i/{$household->token}/rsvp", ['attending' => true, 'people_count' => 1])->assertOk();
    }

    $this->postJson("/i/{$household->token}/rsvp", ['attending' => true, 'people_count' => 1])->assertTooManyRequests();
});

it('returns 404 when replying with an invalid token', function () {
    $this->postJson('/i/DoesNotExist99/rsvp', ['attending' => true, 'people_count' => 1])->assertNotFound();
});

it('stores the meal choice when the host offers one', function () {
    [, $household] = invitedHousehold(config: withRsvpSection(['mealOptions' => ['لحم', 'دجاج']]));

    $this->postJson("/i/{$household->token}/rsvp", ['attending' => true, 'people_count' => 1, 'meal' => 'سمك'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('meal');

    $this->postJson("/i/{$household->token}/rsvp", ['attending' => true, 'people_count' => 1, 'meal' => 'دجاج'])
        ->assertOk()
        ->assertJsonPath('rsvp.meal', 'دجاج');
});

it('refuses kids and meal fields the host did not ask for', function () {
    [, $household] = invitedHousehold(config: withRsvpSection(['askKids' => false]));

    $this->postJson("/i/{$household->token}/rsvp", ['attending' => true, 'people_count' => 2, 'kids_count' => 1, 'meal' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['kids_count', 'meal']);
});
