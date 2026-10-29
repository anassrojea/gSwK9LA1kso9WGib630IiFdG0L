<?php

use App\Services\InvitationConfig\InvitationConfig;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlanSeeder;

beforeEach(fn () => $this->seed([PlanSeeder::class, CatalogSeeder::class]));

function setSection(array $config, string $type, array $changes): array
{
    foreach ($config['sections'] as $i => $section) {
        if ($section['type'] === $type) {
            $config['sections'][$i] = array_merge($section, $changes);
        }
    }

    return $config;
}

it('autosaves a guest\'s own draft and syncs the event fields', function () {
    $event = wizardDraft();
    $config = $event->invitation->config;
    $config = setSection($config, 'names', ['first' => 'هند', 'second' => 'سامي']);
    $config = setSection($config, 'date', ['start' => '2027-03-04T19:30:00+03:00']);
    $config = setSection($config, 'place', ['name' => 'قاعة الياسمين', 'address' => 'شارع الملك فهد', 'lat' => 24.7136, 'lng' => 46.6753]);

    $this->patchJson("/events/{$event->id}/config", ['config' => $config])
        ->assertOk()
        ->assertJson(['config_version' => 1, 'title' => 'زفاف هند وسامي']);

    $event->refresh();
    expect($event->title)->toBe('زفاف هند وسامي')
        ->and($event->starts_at->toIso8601String())->toBe('2027-03-04T16:30:00+00:00')
        ->and($event->venue_name)->toBe('قاعة الياسمين')
        ->and($event->address)->toBe('شارع الملك فهد')
        ->and($event->lat)->toBe(24.7136)
        ->and($event->lng)->toBe(46.6753)
        ->and(InvitationConfig::section($event->invitation->fresh()->config, 'names')['first'])->toBe('هند');
});

it('clears the venue when the place section is removed and follows the occasion', function () {
    $event = wizardDraft();
    $config = $event->invitation->config;
    $config['sections'] = array_values(array_filter($config['sections'], fn ($s) => $s['type'] !== 'place'));
    $config['occasion'] = 'engagement';

    $this->patchJson("/events/{$event->id}/config", ['config' => $config])->assertOk();

    expect($event->fresh()->venue_name)->toBeNull()
        ->and($event->fresh()->lat)->toBeNull()
        ->and($event->fresh()->occasion)->toBe('engagement')
        ->and($event->fresh()->title)->toStartWith('خطوبة ');
});

it('rejects an invalid config with errors keyed by path and saves nothing', function () {
    $event = wizardDraft();
    $before = $event->invitation->config;
    $config = setSection($before, 'names', ['first' => '']);
    $config = setSection($config, 'date', ['start' => 'next week']);

    $this->patchJson("/events/{$event->id}/config", ['config' => $config])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['config.sections.2.first', 'config.sections.3.start']);

    expect($event->invitation->fresh()->config)->toEqual($before)
        ->and($event->fresh()->title)->toBe($event->title);
});

it('records the template picked in the design step', function () {
    $event = wizardDraft();
    $config = $event->invitation->config;

    $this->patchJson("/events/{$event->id}/config", ['config' => $config, 'template_id' => 'warda-rose'])->assertOk();
    expect($event->invitation->fresh()->template_id)->toBe('warda-rose');

    $this->patchJson("/events/{$event->id}/config", ['config' => $config, 'template_id' => 'not-a-template'])
        ->assertJsonValidationErrors('template_id');
});
