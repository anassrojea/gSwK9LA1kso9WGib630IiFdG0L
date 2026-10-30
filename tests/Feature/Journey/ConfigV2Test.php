<?php

use App\Models\Event;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\Template;
use App\Models\User;
use App\Services\InvitationConfig\InvitationConfig;
use App\Services\InvitationConfig\InvitationConfigValidator;
use App\Services\OgImage\OgImage;
use App\Services\Wizard\DraftCreator;
use App\Services\Wizard\PlanAdvisor;
use Database\Factories\InvitationFactory;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlanSeeder;

/*
| Config schema v2 (phase 8D): new occasions, the optional kind and the
| thank_you_gallery section. Same fixtures as tests/js/config-schema.test.ts.
*/

function exampleV1(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/config-example.json')), true);
}

function exampleV2(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/config-example-v2.json')), true);
}

it('accepts the v2 example and knows both versions', function () {
    expect(app(InvitationConfigValidator::class)->validate(exampleV2()))->toBe([])
        ->and(InvitationConfigValidator::CURRENT_VERSION)->toBe(2)
        ->and(InvitationConfigValidator::SUPPORTED_VERSIONS)->toBe([1, 2]);
});

it('still validates v1 documents as v1', function (Closure $mutate, string $pointer) {
    $config = exampleV1();
    expect(app(InvitationConfigValidator::class)->validate($config))->toBe([]);

    $mutate($config);
    expect(app(InvitationConfigValidator::class)->validate($config))->toHaveKey($pointer);
})->with([
    'a v2 occasion' => [function (&$c) {
        $c['occasion'] = 'katb_kitab';
    }, '/occasion'],
    'kind' => [function (&$c) {
        $c['kind'] = 'invitation';
    }, '/'],
    'a thank-you gallery' => [function (&$c) {
        $c['sections'][] = ['type' => 'thank_you_gallery', 'message' => 'شكراً'];
    }, '/sections/11/type'],
]);

it('rejects bad v2 input', function (Closure $mutate, string $pointer) {
    $config = exampleV2();
    $mutate($config);

    expect(app(InvitationConfigValidator::class)->validate($config))->toHaveKey($pointer);
})->with([
    'unknown kind' => [function (&$c) {
        $c['kind'] = 'flyer';
    }, '/kind'],
    'unknown occasion' => [function (&$c) {
        $c['occasion'] = 'party';
    }, '/occasion'],
    'thank-you gallery without a message' => [function (&$c) {
        unset($c['sections'][11]['message']);
    }, '/sections/11'],
    'thank-you gallery with 25 photos' => [function (&$c) {
        $c['sections'][11]['photos'] = array_fill(0, 25, ['src' => '/storage/a.jpg']);
    }, '/sections/11/photos'],
    'thank-you photo over http' => [function (&$c) {
        $c['sections'][11]['photos'][0]['src'] = 'http://evil.test/x.jpg';
    }, '/sections/11/photos/0/src'],
    'thank-you signature too long' => [function (&$c) {
        $c['sections'][11]['signature'] = str_repeat('x', 121);
    }, '/sections/11/signature'],
    'unknown top-level key' => [function (&$c) {
        $c['script'] = 'alert(1)';
    }, '/'],
]);

it('upgrades v1 to v2 by changing only the version', function () {
    $v1 = exampleV1();
    $upgraded = InvitationConfig::upgrade($v1);

    expect($upgraded)->toBe(['version' => 2] + $v1)
        ->and(app(InvitationConfigValidator::class)->validate($upgraded))->toBe([])
        ->and(InvitationConfig::upgrade(exampleV2()))->toBe(exampleV2())
        ->and(InvitationConfig::kind($upgraded))->toBe('invitation')
        ->and(InvitationConfig::kind(['kind' => 'thank_you']))->toBe('thank_you');
});

it('keeps rendering a v1 invitation, stored as v1', function () {
    [, $household] = invitedHousehold(config: exampleV1());

    expect(Invitation::query()->first()->config_version)->toBe(1);
    $this->get("/i/{$household->token}")->assertOk()->assertSee('ريم');
});

it('renders a v2 invitation of a new occasion', function () {
    $this->seed([PlanSeeder::class, CatalogSeeder::class]);
    [, $household] = invitedHousehold(config: InvitationFactory::sampleConfig('katb-kitab-ringbox'));

    expect(Invitation::query()->first()->config_version)->toBe(2);
    $this->get("/i/{$household->token}")->assertOk()->assertSee('دعوة كتب الكتاب')->assertSee('جود');
});

it('writes v2 from the wizard, even when the browser sends a v1 document', function () {
    $owner = User::factory()->create();
    $event = Event::factory()->draft()->create(['owner_id' => $owner->id]);
    Invitation::factory()->for($event)->create(['config' => exampleV1(), 'published_at' => null]);

    $this->actingAs($owner)->patchJson("/events/{$event->id}/config", ['config' => exampleV1()])
        ->assertOk()
        ->assertJson(['config_version' => 2]);

    expect($event->invitation->fresh()->config['version'])->toBe(2);
});

it('hands the editor the upgraded config of an old draft', function () {
    $this->seed([PlanSeeder::class, CatalogSeeder::class]);
    $owner = User::factory()->create();
    $event = Event::factory()->draft()->create(['owner_id' => $owner->id]);
    Invitation::factory()->for($event)->create(['config' => exampleV1(), 'published_at' => null]);

    $this->actingAs($owner)->get("/create/{$event->id}/details")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('config.version', 2));
});

it('creates drafts for every new occasion from its template, in v2', function (string $occasion, string $template) {
    $this->seed([PlanSeeder::class, CatalogSeeder::class]);
    $owner = User::factory()->create();

    $this->actingAs($owner)->post('/create', ['occasion' => $occasion])->assertRedirect();

    $event = Event::query()->latest()->first();
    expect($event->occasion)->toBe($occasion)
        ->and($event->invitation->template_id)->toBe($template)
        ->and($event->invitation->config['version'])->toBe(2);
})->with([
    ['katb_kitab', 'katb-kitab-ringbox'],
    ['eid', 'eid-envelope'],
    ['housewarming', 'housewarming-doors'],
    ['hajj_return', 'hajj-return-doors'],
    ['welcome_home', 'welcome-home-envelope'],
    ['corporate', 'corporate-iftar-doors'],
    ['save_the_date', 'save-the-date-envelope'],
]);

it('lists the new occasions as wizard tiles, but never the thank-you card', function () {
    $this->get('/create')->assertOk()->assertInertia(fn ($page) => $page
        ->where('occasions', fn ($list) => collect($list)->contains('katb_kitab') && collect($list)->contains('save_the_date') && ! collect($list)->contains('thank_you')));

    $this->post('/create', ['occasion' => 'thank_you'])->assertSessionHasErrors('occasion');
});

it('adapts a template of another occasion to a new occasion', function () {
    $this->seed([PlanSeeder::class, CatalogSeeder::class]);
    $config = DraftCreator::configFor('hajj_return', Template::query()->find('layali-envelope'));

    expect($config['version'])->toBe(2)
        ->and($config['occasion'])->toBe('hajj_return')
        ->and(InvitationConfig::section($config, 'names'))->toMatchArray(['first' => 'الحاج أبو خالد'])
        ->and(app(InvitationConfigValidator::class)->validate($config))->toBe([]);
});

it('prices new occasions with PlanAdvisor like any other', function () {
    $this->seed([PlanSeeder::class, CatalogSeeder::class]);
    $premium = Event::factory()->draft()->create(['occasion' => 'katb_kitab']);
    $free = Event::factory()->draft()->create(['occasion' => 'eid']);

    $advisor = app(PlanAdvisor::class);
    expect($advisor->advise($premium, InvitationFactory::sampleConfig('katb-kitab-ringbox'))['tier'])->toBe('premium')
        ->and($advisor->advise($free, InvitationFactory::sampleConfig('eid-envelope'))['tier'])->toBe('free');
});

it('puts the new occasion on the link preview card', function () {
    $this->seed([PlanSeeder::class, CatalogSeeder::class]);
    [$event, $household] = invitedHousehold(config: InvitationFactory::sampleConfig('hajj-return-doors'));
    $invitation = $event->invitation;

    $html = app(OgImage::class)->html($invitation, $invitation->renderConfig(), Household::query()->find($household->id));

    expect($html)->toContain('حمد الله على السلامة');
});

it('ships a valid English corporate template', function () {
    $config = InvitationFactory::sampleConfig('corporate-launch-envelope');

    expect($config['locale'])->toBe('en')
        ->and($config['occasion'])->toBe('corporate')
        ->and(app(InvitationConfigValidator::class)->validate($config))->toBe([]);
});
