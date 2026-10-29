<?php

use App\Models\Event;
use App\Models\Invitation;
use App\Services\PublishGate;
use Database\Factories\InvitationFactory;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed([PlanSeeder::class, CatalogSeeder::class]);

    // a free invitation: free opening (envelope) and free theme (midnight-navy), no named families
    $config = InvitationFactory::sampleConfig('layali-envelope');
    $config['theme'] = ['id' => 'midnight-navy'] + json_decode(file_get_contents(base_path('templates/themes/midnight-navy.json')), true)['tokens']
        + ['fonts' => json_decode(file_get_contents(base_path('templates/themes/midnight-navy.json')), true)['fonts']];
    unset($config['theme']['radius']);

    $this->event = Event::factory()->create(['plan_id' => 'free', 'status' => 'active']);
    $this->invitation = Invitation::factory()->for($this->event)->create(['config' => $config, 'published_at' => now()]);
    $this->config = $config;
});

it('starts from an invitation the free plan covers', function () {
    expect(app(PublishGate::class)->check($this->event, $this->config)['allowed'])->toBeTrue();
});

it('blocks switching a published free invitation to a Premium opening', function () {
    $config = $this->config;
    $config['opening'] = ['id' => 'rose', 'options' => ['petalColor' => '#F4A6BB']];

    $this->actingAs($this->event->owner)
        ->patchJson("/events/{$this->event->id}/config", ['config' => $config])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('config');

    expect($this->invitation->fresh()->config['opening']['id'])->toBe('envelope');
});

it('still allows ordinary edits to a published free invitation', function () {
    $config = $this->config;
    $config['sections'][2]['first'] = 'لين';

    $this->actingAs($this->event->owner)
        ->patchJson("/events/{$this->event->id}/config", ['config' => $config])
        ->assertOk();
});

it('allows the Premium opening once the event is on Premium', function () {
    $this->event->update(['plan_id' => 'premium']);
    $config = $this->config;
    $config['opening'] = ['id' => 'rose'];

    $this->actingAs($this->event->owner)
        ->patchJson("/events/{$this->event->id}/config", ['config' => $config])
        ->assertOk();
});
