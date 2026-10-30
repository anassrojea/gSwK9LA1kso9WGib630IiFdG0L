<?php

use App\Models\AuditLog;
use App\Models\DesignerProfile;
use App\Models\Invitation;
use App\Models\Template;
use App\Models\TemplateRevision;
use App\Models\Theme;
use App\Models\User;
use App\Services\Marketplace\Designers;
use App\Services\Marketplace\TemplateReview;
use App\Services\Marketplace\TemplateStudio;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Marketplace;
use Tests\Support\Payments;

beforeEach(function () {
    Payments::setUp();
});

it('lets a user apply as a designer only with the terms, and keeps payout details encrypted', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/designer/apply', ['display_name_ar' => 'نور', 'display_name_en' => 'Noor'])->assertSessionHasErrors('accept_terms');
    expect(DesignerProfile::query()->count())->toBe(0);

    $this->actingAs($user)->post('/designer/apply', [
        'display_name_ar' => 'نور', 'display_name_en' => 'Noor Studio', 'accept_terms' => '1',
        'portfolio_links' => ['https://behance.net/noor'],
        'payout_details' => ['holder' => 'Noor', 'iban' => 'SA0380000000608010167519'],
    ])->assertRedirect('/designer');

    $profile = DesignerProfile::query()->sole();
    expect($profile->status)->toBe('pending')
        ->and($profile->slug)->toBe('noor-studio')
        ->and($profile->terms_version)->toBe(config('marketplace.terms_version'))
        ->and($profile->payout_details)->toBe(['holder' => 'Noor', 'iban' => 'SA0380000000608010167519']);

    $raw = DB::table('designer_profiles')->value('payout_details');
    expect($raw)->not->toContain('SA0380000000608010167519');
    expect(AuditLog::query()->where('action', 'designer.applied')->exists())->toBeTrue();

    // pending: the application page, and no studio yet
    $this->actingAs($user)->get('/designer')->assertInertia(fn (Assert $page) => $page->component('designer/Apply')->where('profile.status', 'pending'));
    $this->actingAs($user)->get('/designer/themes/create')->assertForbidden();
});

it('approves, suspends and sets the commission through the admin, audit-logged', function () {
    $profile = Marketplace::designer(approved: false);
    $admin = Marketplace::admin();
    $designers = app(Designers::class);

    expect(fn () => $designers->approve($profile, $profile->user))->toThrow(AuthorizationException::class);

    $designers->approve($profile, $admin);
    expect($profile->fresh()->status)->toBe('approved');

    $designers->setCommission($profile, 8000, $admin);
    expect($profile->fresh()->shareBp())->toBe(8000);
    expect(fn () => $designers->setCommission($profile, 12000, $admin))->toThrow(ValidationException::class);

    $template = Marketplace::published($profile->fresh());
    expect(Template::query()->onSale()->whereKey($template->id)->exists())->toBeTrue();

    $designers->suspend($profile, $admin, 'Copied artwork');
    expect($profile->fresh()->status)->toBe('suspended')
        ->and(Template::query()->onSale()->whereKey($template->id)->exists())->toBeFalse();

    expect(AuditLog::query()->whereIn('action', ['designer.approved', 'designer.commission_changed', 'designer.suspended'])->where('actor_id', $admin->id)->count())->toBe(3);
});

it('validates designer themes like the admin theme form', function () {
    $profile = Marketplace::designer();

    $this->actingAs($profile->user)->post('/designer/themes', [
        'name_ar' => 'ثيم', 'name_en' => 'Theme',
        'tokens' => ['colors' => ['bg' => 'pink', 'ink' => '#000', 'accent' => '#111', 'soft' => '#222', 'gold' => '#333']],
        'fonts' => ['display' => 'Amiri', 'body' => 'Amiri', 'label' => 'Amiri'],
    ])->assertSessionHasErrors('tokens.colors.bg');

    $this->actingAs($profile->user)->post('/designer/themes', [
        'name_ar' => 'ثيم', 'name_en' => 'Theme',
        'tokens' => ['colors' => ['bg' => '#FFFFFF', 'ink' => '#000', 'accent' => '#111', 'soft' => '#222', 'gold' => '#333'], 'radius' => 12],
        'fonts' => ['display' => 'Amiri', 'body' => 'Amiri', 'label' => 'Amiri'],
    ])->assertRedirect('/designer');

    $theme = Theme::query()->where('designer_id', $profile->user_id)->sole();
    expect($theme->id)->toStartWith('d-')->and($theme->tokens['radius'])->toBe(12);
});

it('checks the default config with the invitation schema on save', function () {
    $profile = Marketplace::designer();
    $theme = Marketplace::theme($profile);
    $data = Marketplace::templateData($theme);

    $bad = $data;
    $bad['default_config']['sections'] = [['type' => 'date', 'start' => 'tomorrow']];
    $this->actingAs($profile->user)->post('/designer/templates', $bad)->assertSessionHasErrors('default_config');

    $this->actingAs($profile->user)->post('/designer/templates', ['default_config' => '{not json'] + $data)->assertSessionHasErrors('default_config');

    // prices within the admin's bounds, USD required
    $this->actingAs($profile->user)->post('/designer/templates', ['prices' => ['USD' => 10, 'SYP' => 100000]] + $data)->assertSessionHasErrors('prices.USD');
    $this->actingAs($profile->user)->post('/designer/templates', ['prices' => ['SYP' => 100000]] + $data)->assertSessionHasErrors('prices.USD');

    // a theme that isn't the designer's own
    $this->actingAs($profile->user)->post('/designer/templates', ['theme_id' => 'olive-sand'] + $data)->assertSessionHasErrors('theme_id');

    $this->actingAs($profile->user)->post('/designer/templates', $data)->assertRedirect();
    $template = Template::query()->where('designer_id', $profile->user_id)->sole();
    expect($template->marketplace_status)->toBe('draft')
        ->and($template->active)->toBeFalse()
        ->and($template->default_config['theme']['id'])->toBe($theme->id)
        ->and($template->default_config['opening']['id'])->toBe('rose')
        ->and($template->revisions()->sole()->status)->toBe('draft');
});

it('runs a template through draft, review, rejection and approval', function () {
    $profile = Marketplace::designer();
    $theme = Marketplace::theme($profile);
    $studio = app(TemplateStudio::class);
    $review = app(TemplateReview::class);
    $admin = Marketplace::admin();

    $template = $studio->createTemplate($profile, Marketplace::templateData($theme));
    $this->actingAs($profile->user)->post("/designer/templates/{$template->id}/submit")->assertRedirect();
    expect($template->fresh()->marketplace_status)->toBe('in_review');

    // in review: locked for the designer
    $this->actingAs($profile->user)->put("/designer/templates/{$template->id}", Marketplace::templateData($theme))->assertSessionHasErrors('template');

    $revision = $template->revisions()->sole();
    expect(fn () => $review->reject($revision, $admin, ' '))->toThrow(ValidationException::class);
    $review->reject($revision, $admin, 'The names overlap the rose on small phones.');
    expect($template->fresh()->marketplace_status)->toBe('rejected')
        ->and($revision->fresh()->rejection_reason)->toBe('The names overlap the rose on small phones.');

    $this->actingAs($profile->user)->get('/designer')->assertInertia(fn (Assert $page) => $page->component('designer/Index')
        ->where('templates.0.revision.status', 'rejected')
        ->where('templates.0.revision.rejection_reason', 'The names overlap the rose on small phones.'));

    // fixing it goes back to draft, then to review again
    $studio->saveDraft($profile, $template->fresh(), Marketplace::templateData($theme, ['name_en' => 'Damascus Jasmine II']));
    expect($template->fresh()->marketplace_status)->toBe('draft');
    $revision = $studio->submit($profile, $template->fresh());

    $review->approve($revision, $admin);
    $template->refresh();
    expect($template->marketplace_status)->toBe('published')
        ->and($template->active)->toBeTrue()
        ->and($template->name_en)->toBe('Damascus Jasmine II')
        ->and($template->live_revision_id)->toBe($revision->id)
        ->and($template->published_at)->not->toBeNull();
    expect(AuditLog::query()->where('action', 'marketplace.revision_approved')->where('actor_id', $admin->id)->exists())->toBeTrue();

    // off sale and back, without a new review
    $studio->unpublish($template, $profile);
    expect(Template::query()->onSale()->whereKey($template->id)->exists())->toBeFalse();
    $studio->republish($profile, $template->fresh());
    expect(Template::query()->onSale()->whereKey($template->id)->exists())->toBeTrue();
});

it('versions a published template without touching invitations already using it', function () {
    $profile = Marketplace::designer();
    $template = Marketplace::published($profile);
    $theme = $template->theme;
    $before = $template->default_config;

    $event = Marketplace::eventUsing($template);
    $event->invitation->update(['published_at' => now()]);

    // editing a live template: revision 2 as a draft, the live template unchanged
    $config = $before;
    $config['opening']['options'] = ['headline' => 'A new headline'];
    $revision = app(TemplateStudio::class)->saveDraft($profile, $template, Marketplace::templateData($theme, ['default_config' => $config, 'name_en' => 'Jasmine v2']));
    expect($revision->number)->toBe(2)->and($revision->status)->toBe('draft');
    $template->refresh();
    expect($template->marketplace_status)->toBe('published')
        ->and($template->name_en)->toBe('Damascus Jasmine')
        ->and($template->default_config)->toEqual($before);

    $submitted = app(TemplateStudio::class)->submit($profile, $template);
    app(TemplateReview::class)->approve($submitted, Marketplace::admin());

    $template->refresh();
    expect($template->name_en)->toBe('Jasmine v2')
        ->and($template->default_config['opening']['options']['headline'])->toBe('A new headline')
        ->and(TemplateRevision::query()->where('template_id', $template->id)->where('number', 1)->value('status'))->toBe('superseded');

    // the invitation keeps its own copy of the config
    expect(Invitation::query()->find($event->invitation->id)->config['opening']['options']['headline'] ?? null)->not->toBe('A new headline')
        ->and(Invitation::query()->find($event->invitation->id)->config)->toEqual($before);
});

it('shows drafts in the preview only to their designer and admins', function () {
    $profile = Marketplace::designer();
    $theme = Marketplace::theme($profile);
    $template = app(TemplateStudio::class)->createTemplate($profile, Marketplace::templateData($theme));
    $revision = $template->revisions()->sole();
    $url = "/designer/revisions/{$revision->id}/preview";

    $this->get($url)->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    $this->actingAs(Marketplace::designer()->user)->get($url)->assertForbidden();

    $this->actingAs($profile->user)->get($url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('data-preview-ribbon', false);
    $this->actingAs(Marketplace::admin())->get($url)->assertOk();
});

it('keeps each designer to their own templates and themes', function () {
    $owner = Marketplace::designer();
    $template = Marketplace::published($owner);
    $other = Marketplace::designer();
    Marketplace::theme($other);

    $this->actingAs($other->user)->get("/designer/templates/{$template->id}/edit")->assertForbidden();
    $this->actingAs($other->user)->put("/designer/templates/{$template->id}", Marketplace::templateData($template->theme))->assertForbidden();
    $this->actingAs($other->user)->post("/designer/templates/{$template->id}/submit")->assertForbidden();
    $this->actingAs($other->user)->post("/designer/templates/{$template->id}/unpublish")->assertForbidden();
    $this->actingAs($other->user)->get("/designer/themes/{$template->theme_id}/edit")->assertForbidden();
    // a house template is nobody's
    $this->actingAs($other->user)->get('/designer/templates/warda-rose/edit')->assertForbidden();

    $this->actingAs($other->user)->get('/designer')->assertInertia(fn (Assert $page) => $page->component('designer/Index')->has('templates', 0));
});

it('stores cover images on the media disk under the designer folder', function () {
    Storage::fake('public');
    $profile = Marketplace::designer();
    $template = Marketplace::published($profile);

    $this->actingAs($profile->user)->post("/designer/templates/{$template->id}/cover", ['cover' => UploadedFile::fake()->image('cover.jpg', 800, 1000)])->assertRedirect();

    $path = $template->fresh()->cover_path;
    expect($path)->toStartWith("designers/{$profile->user_id}/covers/");
    Storage::disk('public')->assertExists($path);

    $this->actingAs($profile->user)->post("/designer/templates/{$template->id}/cover", ['cover' => UploadedFile::fake()->create('cover.pdf', 10, 'application/pdf')])->assertSessionHasErrors('cover');
});

it('lists submitted revisions in the admin review queue', function () {
    $profile = Marketplace::designer();
    $theme = Marketplace::theme($profile);
    $template = app(TemplateStudio::class)->createTemplate($profile, Marketplace::templateData($theme, ['name_en' => 'Queue Me']));
    app(TemplateStudio::class)->submit($profile, $template);

    $this->actingAs(Marketplace::admin())->get('/admin/marketplace/review')->assertOk()->assertSee($template->id);
    $this->actingAs(Marketplace::admin())->get('/admin/marketplace/designers')->assertOk();
    $this->actingAs(Marketplace::admin())->get('/admin/marketplace/payouts')->assertOk();
    $this->actingAs(Marketplace::admin())->get('/admin/marketplace/vendors')->assertOk();
    $this->actingAs($profile->user)->get('/admin/marketplace/review')->assertForbidden();
});
