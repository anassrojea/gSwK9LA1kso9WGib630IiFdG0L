<?php

use App\Models\User;

it('generates a hall layout over HTTP', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/halls/generate', ['tables' => 12, 'chairs' => 10, 'layout' => 'split'])
        ->assertOk()
        ->assertJsonCount(12, 'tables')
        ->assertJsonPath('tables.0.seats', 10)
        ->assertJsonPath('sections.1.id', 'women');
});

it('validates the hall generator input', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/halls/generate', ['tables' => 0, 'chairs' => 99])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tables', 'chairs']);
});
