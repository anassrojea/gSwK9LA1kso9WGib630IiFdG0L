<?php

use App\Models\Event;
use App\Models\Invitation;
use App\Services\InvitationConfig\InvitationConfig;
use App\Services\InvitationConfig\InvitationConfigValidator;
use Database\Factories\InvitationFactory;
use Illuminate\Validation\ValidationException;

function exampleConfig(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/config-example.json')), true);
}

it('accepts the example config from the plan (section 6.2)', function () {
    expect(app(InvitationConfigValidator::class)->validate(exampleConfig()))->toBe([]);
});

it('accepts every template shipped in templates/', function (string $file) {
    $template = json_decode(file_get_contents($file), true);

    expect(app(InvitationConfigValidator::class)->validate($template['default_config']))->toBe([]);
})->with(glob(__DIR__.'/../../templates/invitations/*.json'));

it('rejects bad input', function (Closure $mutate, string $pointer) {
    $config = exampleConfig();
    $mutate($config);

    $errors = app(InvitationConfigValidator::class)->validate($config);

    expect($errors)->toHaveKey($pointer);
})->with([
    'missing version' => [function (&$c) {
        unset($c['version']);
    }, '/version'],
    'unsupported version' => [function (&$c) {
        $c['version'] = 99;
    }, '/version'],
    'unknown occasion' => [function (&$c) {
        $c['occasion'] = 'party';
    }, '/occasion'],
    'unknown locale' => [function (&$c) {
        $c['locale'] = 'fr';
    }, '/locale'],
    'unknown opening' => [function (&$c) {
        $c['opening']['id'] = 'confetti-cannon';
    }, '/opening/id'],
    'color that is not hex' => [function (&$c) {
        $c['theme']['colors']['bg'] = 'red';
    }, '/theme/colors/bg'],
    'missing theme color' => [function (&$c) {
        unset($c['theme']['colors']['accent']);
    }, '/theme/colors'],
    'font with css injection' => [function (&$c) {
        $c['theme']['fonts']['body'] = 'Amiri;}</style>';
    }, '/theme/fonts/body'],
    'unknown top-level key' => [function (&$c) {
        $c['script'] = 'alert(1)';
    }, '/'],
    'no sections' => [function (&$c) {
        $c['sections'] = [];
    }, '/sections'],
    'unknown section type' => [function (&$c) {
        $c['sections'][] = ['type' => 'fireworks'];
    }, '/sections/11/type'],
    'section with an unknown property' => [function (&$c) {
        $c['sections'][0]['onclick'] = 'x';
    }, '/sections/0'],
    'names without first name' => [function (&$c) {
        unset($c['sections'][2]['first']);
    }, '/sections/2'],
    'date that is not a date' => [function (&$c) {
        $c['sections'][3]['start'] = 'next Thursday';
    }, '/sections/3/start'],
    'program time out of range' => [function (&$c) {
        $c['sections'][5]['items'][0]['time'] = '25:00';
    }, '/sections/5/items/0/time'],
    'latitude out of range' => [function (&$c) {
        $c['sections'][6]['lat'] = 123;
    }, '/sections/6/lat'],
    'dress code color not hex' => [function (&$c) {
        $c['sections'][7]['colors'][] = 'pink';
    }, '/sections/7/colors/3'],
]);

it('refuses to save an invalid config on the model', function () {
    $event = Event::factory()->create();
    $config = exampleConfig();
    $config['opening']['id'] = 'nope';

    expect(fn () => Invitation::factory()->for($event)->create(['config' => $config]))
        ->toThrow(ValidationException::class);

    expect(Invitation::query()->count())->toBe(0);
});

it('stores the config version on save', function () {
    $invitation = Invitation::factory()->create(['config' => exampleConfig()]);

    expect($invitation->config_version)->toBe(1);
});

it('answers the autosave endpoint with errors keyed by path', function () {
    $event = Event::factory()->create();
    Invitation::factory()->for($event)->create();
    $config = InvitationFactory::sampleConfig();
    $config['sections'][3]['start'] = 'tomorrow';
    $config['theme']['colors']['ink'] = 'black';

    $this->actingAs($event->owner)
        ->patchJson("/events/{$event->id}/config", ['config' => $config])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['config.sections.3.start', 'config.theme.colors.ink']);
});

it('keeps empty option objects valid after an array round-trip', function () {
    $config = exampleConfig();
    $config['opening']['options'] = [];
    $config['effects'] = [];

    expect(app(InvitationConfigValidator::class)->validate($config))->toBe([]);
});

it('upgrades configs to the current version and filters hidden sections', function () {
    $config = exampleConfig();
    $config['sections'][1]['hidden'] = true;

    $visible = InvitationConfig::visibleSections(InvitationConfig::upgrade($config));

    expect($visible)->toHaveCount(10)
        ->and(collect($visible)->pluck('type'))->not->toContain('hosts');
});
