<?php

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Templates\TemplateResource;
use App\Filament\Resources\Themes\ThemeResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Order;
use App\Models\User;
use Tests\Support\Payments;

beforeEach(function () {
    Payments::setUp();
    $this->admin = User::factory()->admin()->create(['name' => 'Admin', 'locale' => 'ar']);
    $this->actingAs($this->admin);
});

it('renders every admin page in Arabic, right to left, with grouped navigation', function () {
    $event = Payments::event();
    Order::factory()->create(['user_id' => $event->owner_id, 'event_id' => $event->id]);

    $pages = [
        '/admin',
        '/admin/orders',
        '/admin/prices',
        '/admin/discount-codes',
        '/admin/users',
        UserResource::getUrl('view', ['record' => $event->owner_id]),
        UserResource::getUrl('edit', ['record' => $event->owner_id]),
        '/admin/events',
        EventResource::getUrl('view', ['record' => $event]),
        '/admin/templates',
        TemplateResource::getUrl('create'),
        TemplateResource::getUrl('edit', ['record' => 'warda-rose']),
        '/admin/themes',
        ThemeResource::getUrl('create'),
        ThemeResource::getUrl('edit', ['record' => 'royal-ink']),
        '/admin/openings',
        '/admin/plans',
        '/admin/plans/premium/edit',
        '/admin/addons',
        '/admin/audit-logs',
    ];

    foreach ($pages as $url) {
        $this->get($url)->assertOk()->assertSee('dir="rtl"', escape: false);
    }

    $this->get('/admin')
        ->assertSeeInOrder([
            trans('admin.dashboard.title', [], 'ar'),
            trans('admin.nav.sales', [], 'ar'),
            trans('app.admin.orders.plural', [], 'ar'),
            trans('app.admin.orders.waiting', [], 'ar'),
            trans('app.admin.prices.plural', [], 'ar'),
            trans('admin.discounts.plural', [], 'ar'),
            trans('admin.nav.customers', [], 'ar'),
            trans('admin.users.plural', [], 'ar'),
            trans('admin.events.plural', [], 'ar'),
            trans('admin.nav.catalog', [], 'ar'),
            trans('admin.templates.plural', [], 'ar'),
            trans('admin.themes.plural', [], 'ar'),
            trans('admin.openings.plural', [], 'ar'),
            trans('admin.plans.plural', [], 'ar'),
            trans('app.admin.addons.plural', [], 'ar'),
            trans('admin.nav.system', [], 'ar'),
            trans('app.admin.audit.plural', [], 'ar'),
        ])
        ->assertSee('English');
});

it('follows the admin\'s language and switches it from the user menu', function () {
    $this->get('/admin/templates')->assertSee('القوالب')->assertSee('dir="rtl"', escape: false);

    $this->from('/admin/templates')->get(route('locale', 'en'))->assertRedirect('/admin/templates');
    expect($this->admin->fresh()->locale)->toBe('en');

    $this->actingAs($this->admin->fresh())->get('/admin/templates')
        ->assertSee('Templates')
        // the navigation groups too, though the panel was built before the locale was known
        ->assertSee('Catalog')
        ->assertDontSee('الكتالوج')
        ->assertSee('dir="ltr"', escape: false)
        ->assertSee('العربية');
});

it('has an Arabic label for every admin string', function () {
    $flatten = function (array $lines, string $prefix = '') use (&$flatten): array {
        $keys = [];
        foreach ($lines as $key => $value) {
            $keys = is_array($value) ? [...$keys, ...$flatten($value, "{$prefix}{$key}.")] : [...$keys, "{$prefix}{$key}"];
        }

        return $keys;
    };

    $en = $flatten(require lang_path('en/admin.php'));
    $ar = $flatten(require lang_path('ar/admin.php'));

    expect($ar)->toEqualCanonicalizing($en);
    foreach ($en as $key) {
        expect(trans("admin.{$key}", [], 'ar'))->not->toBe("admin.{$key}");
    }
    expect(trans('admin.nav.sales', [], 'ar'))->toBe('المبيعات')
        ->and(trans('admin.discounts.plural', [], 'ar'))->toBe('أكواد الخصم')
        // Filament's own strings come in Arabic too
        ->and(trans('filament-panels::layout.direction', [], 'ar'))->toBe('rtl');
});
