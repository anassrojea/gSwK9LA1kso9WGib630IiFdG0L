<?php

use App\Filament\Resources\Openings\Pages\ManageOpenings;
use App\Filament\Resources\Templates\Pages\EditTemplate;
use App\Filament\Resources\Templates\Pages\ListTemplates;
use App\Filament\Resources\Themes\Pages\CreateTheme;
use App\Filament\Resources\Themes\Pages\EditTheme;
use App\Http\Controllers\Admin\PreviewController;
use App\Models\AuditLog;
use App\Models\Opening;
use App\Models\Template;
use App\Models\Theme;
use App\Models\User;
use App\Services\PublishGate;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\Payments;

beforeEach(function () {
    Payments::setUp();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

function templateJson(string $id = 'warda-rose', ?callable $change = null): string
{
    $config = Template::query()->findOrFail($id)->default_config;
    if ($change) {
        $config = $change($config);
    }

    return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

it('checks a template\'s JSON against the schema on save, with path-keyed errors', function () {
    $before = Template::query()->find('warda-rose')->default_config;

    Livewire::test(EditTemplate::class, ['record' => 'warda-rose'])
        ->fillForm(['default_config' => '{"version": 1, "occasion": '])
        ->call('save')
        ->assertHasFormErrors(['default_config']);

    $page = Livewire::test(EditTemplate::class, ['record' => 'warda-rose'])
        ->fillForm(['default_config' => templateJson(change: function (array $config) {
            unset($config['theme']['colors']['bg']);
            foreach ($config['sections'] as $i => $section) {
                if ($section['type'] === 'date') {
                    $config['sections'][$i]['start'] = 'next Thursday';
                }
            }

            return $config;
        })])
        ->call('save')
        ->assertHasFormErrors(['default_config']);

    $messages = collect($page->errors()->get('data.default_config'));
    expect($messages->first(fn ($m) => str_starts_with($m, 'theme.colors')))->not->toBeNull()
        ->and($messages->first(fn ($m) => preg_match('/^sections\.\d+ \(date\)\.start: /', $m)))->not->toBeNull();

    // the config must agree with the template's opening
    Livewire::test(EditTemplate::class, ['record' => 'warda-rose'])
        ->fillForm(['opening_id' => 'doors'])
        ->call('save')
        ->assertHasFormErrors(['default_config']);

    expect(Template::query()->find('warda-rose')->default_config)->toEqual($before)
        ->and(Template::query()->find('warda-rose')->admin_edited_at)->toBeNull();

    // a valid edit saves, is flagged as an admin edit and logged
    Livewire::test(EditTemplate::class, ['record' => 'warda-rose'])
        ->fillForm(['default_config' => templateJson(change: fn ($c) => ['effects' => ['fallingPetals' => false]] + $c), 'sort' => 5])
        ->call('save')
        ->assertHasNoFormErrors();

    $template = Template::query()->find('warda-rose');
    expect($template->default_config['effects']['fallingPetals'])->toBeFalse()
        ->and($template->sort)->toBe(5)
        ->and($template->admin_edited_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'catalog.template_updated')->value('data')['changed'])->toContain('default_config', 'sort');
});

it('keeps admin edits when the catalog is seeded again, until reset to templates/', function () {
    Livewire::test(EditTemplate::class, ['record' => 'warda-rose'])
        ->fillForm(['name_en' => 'Warda (admin)'])
        ->call('save')
        ->assertHasNoFormErrors();
    Template::query()->whereKey('layali-envelope')->update(['name_en' => 'Changed in the database only']);

    $this->seed(CatalogSeeder::class);

    expect(Template::query()->find('warda-rose')->name_en)->toBe('Warda (admin)')
        // a row nobody saved in the admin follows its file
        ->and(Template::query()->find('layali-envelope')->name_en)->toBe('Layali');

    // the import action follows the same rule, and can be told to overwrite
    Livewire::test(ListTemplates::class)->callAction('import', data: ['overwrite' => false])->assertNotified();
    expect(Template::query()->find('warda-rose')->name_en)->toBe('Warda (admin)');

    Livewire::test(ListTemplates::class)
        ->assertActionVisible(TestAction::make('reset')->table(Template::query()->find('warda-rose')))
        ->callAction(TestAction::make('reset')->table(Template::query()->find('warda-rose')));

    $template = Template::query()->find('warda-rose');
    expect($template->name_en)->toBe('Warda')
        ->and($template->admin_edited_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'catalog.reset')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'catalog.imported')->exists())->toBeTrue();
});

it('validates theme tokens like the schema: hex colours, Google Fonts names, radius', function () {
    Livewire::test(EditTheme::class, ['record' => 'royal-ink'])
        ->fillForm([
            'tokens.colors.bg' => 'red',
            'tokens.colors.goldLight' => '#12345',
            'fonts.display' => 'Comic <Sans>',
            'tokens.radius' => 40,
        ])
        ->call('save')
        ->assertHasFormErrors(['tokens.colors.bg', 'tokens.colors.goldLight', 'fonts.display', 'tokens.radius']);

    expect(Theme::query()->find('royal-ink')->tokens['colors']['bg'])->toBe('#F7F3EA');

    // the schema check runs even past the field rules (a missing required colour)
    Livewire::test(CreateTheme::class)
        ->fillForm([
            'id' => 'sea-glass',
            'name_ar' => 'زجاج البحر',
            'name_en' => 'Sea glass',
            'tier' => 'premium',
            'tokens.colors' => ['bg' => '#F4FAF9', 'ink' => '#12302C', 'accent' => '#1F7A6D', 'soft' => '#5E7C77', 'gold' => '#C9A35C', 'surface' => '', 'label' => null],
            'fonts' => ['display' => 'El Messiri', 'body' => 'Amiri', 'label' => 'Cairo'],
            'tokens.radius' => 12,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $theme = Theme::query()->find('sea-glass');
    expect($theme->tokens)->toEqual(['colors' => ['bg' => '#F4FAF9', 'ink' => '#12302C', 'accent' => '#1F7A6D', 'soft' => '#5E7C77', 'gold' => '#C9A35C'], 'radius' => 12])
        ->and($theme->toConfig()['fonts']['display'])->toBe('El Messiri')
        ->and($theme->admin_edited_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'catalog.theme_created')->exists())->toBeTrue();
});

it('sends tier changes straight to PlanAdvisor and PublishGate', function () {
    $event = Payments::event();
    $event->invitation->update(['config' => Payments::freeConfig()]);
    $gate = app(PublishGate::class);

    expect($gate->check($event->fresh())['allowed'])->toBeTrue();

    // the free theme becomes Signature in the admin
    Livewire::test(EditTheme::class, ['record' => 'olive-sand'])
        ->fillForm(['tier' => 'signature'])
        ->call('save')
        ->assertHasNoFormErrors();

    $check = $gate->check($event->fresh());
    expect($check['allowed'])->toBeFalse()
        ->and($check['required'])->toBe('signature')
        ->and($check['missing'])->toBe(['theme']);

    // and back; then the free opening becomes Premium
    Livewire::test(EditTheme::class, ['record' => 'olive-sand'])->fillForm(['tier' => 'free'])->call('save');
    Livewire::test(ManageOpenings::class)
        ->callAction(TestAction::make('edit')->table(Opening::query()->find('envelope')), data: ['tier' => 'premium'])
        ->assertHasNoActionErrors();

    $check = $gate->check($event->fresh());
    expect($check)->toMatchArray(['allowed' => false, 'required' => 'premium', 'missing' => ['opening']])
        ->and(AuditLog::query()->where('action', 'catalog.opening_updated')->value('data')['tier'])->toEqual(['from' => 'free', 'to' => 'premium']);

    // a Premium event is fine again
    $event->update(['plan_id' => 'premium']);
    expect($gate->check($event->fresh())['allowed'])->toBeTrue();
});

it('previews templates and events through signed, admin-only links', function () {
    $template = Template::query()->find('warda-rose');
    $url = PreviewController::templateUrl($template);

    $this->get($url)
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('data-preview-ribbon', escape: false)
        ->assertSee('ريم');

    // a theme previewed on the template
    $this->get(PreviewController::templateUrl($template, ['theme' => 'royal-ink']))->assertOk()->assertSee('#2F5DA8');

    // unsigned, tampered or expired links are refused
    $this->get(route('admin.preview.template', $template))->assertForbidden();
    $this->get($url.'x')->assertForbidden();
    $this->travel(PreviewController::TTL_MINUTES + 1)->minutes();
    $this->get($url)->assertForbidden();
    $this->travelBack();

    // and nobody but an admin opens them, even with a valid link
    $this->actingAs(User::factory()->create())->get(PreviewController::templateUrl($template))->assertForbidden();
    $event = Payments::event();
    $this->actingAs($event->owner)->get(PreviewController::eventUrl($event))->assertForbidden();
    auth()->logout();
    $this->get(PreviewController::templateUrl($template))->assertRedirect();

    $this->actingAs($this->admin)->get(PreviewController::eventUrl($event))->assertOk()->assertSee(trans('admin.preview.ribbon_customer', [], 'ar'));
});
