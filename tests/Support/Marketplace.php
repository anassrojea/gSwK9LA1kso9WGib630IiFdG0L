<?php

namespace Tests\Support;

use App\Models\DesignerProfile;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\Template;
use App\Models\Theme;
use App\Models\User;
use App\Models\VendorListing;
use App\Services\Marketplace\Designers;
use App\Services\Marketplace\TemplateReview;
use App\Services\Marketplace\TemplateStudio;
use App\Services\Marketplace\VendorListings;
use App\Services\Wizard\DraftCreator;

/**
 * Phase 8C test helpers: approved designers, their themes and templates through the
 * real services (the same validation and review path as the portal and Filament).
 */
class Marketplace
{
    public static function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public static function designer(?User $user = null, bool $approved = true): DesignerProfile
    {
        $user ??= User::factory()->create();
        $profile = app(Designers::class)->apply($user, [
            'display_name_ar' => 'استوديو '.$user->name,
            'display_name_en' => 'Studio '.$user->name,
            'payout_details' => ['holder' => $user->name, 'iban' => 'SA0380000000608010167519'],
            'country' => 'SA',
            'accept_terms' => true,
        ]);
        if ($approved) {
            app(Designers::class)->approve($profile, static::admin());
        }

        return $profile->fresh();
    }

    public static function theme(DesignerProfile $profile, array $colors = []): Theme
    {
        return app(TemplateStudio::class)->saveTheme($profile, null, [
            'name_ar' => 'ياسمين',
            'name_en' => 'Jasmine',
            'tokens' => ['colors' => $colors + ['bg' => '#FBF7F0', 'ink' => '#2E2A24', 'accent' => '#2F5D50', 'soft' => '#7B7466', 'gold' => '#B8955A'], 'radius' => 16],
            'fonts' => ['display' => 'Aref Ruqaa', 'body' => 'Amiri', 'label' => 'Almarai'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function templateData(Theme $theme, array $overrides = []): array
    {
        return $overrides + [
            'name_ar' => 'ياسمين الشام',
            'name_en' => 'Damascus Jasmine',
            'description_en' => 'A jasmine wedding',
            'occasion' => 'wedding',
            'opening_id' => 'rose',
            'theme_id' => $theme->id,
            'default_config' => DraftCreator::configFor('wedding', Template::query()->find('warda-rose')),
            'prices' => ['USD' => 1200, 'SAR' => 11500, 'SYP' => 150000],
            'included_in_signature' => false,
        ];
    }

    /**
     * A template on sale: created, submitted and approved.
     */
    public static function published(?DesignerProfile $profile = null, array $overrides = []): Template
    {
        $profile ??= static::designer();
        $theme = static::theme($profile);
        $studio = app(TemplateStudio::class);
        $template = $studio->createTemplate($profile, static::templateData($theme, $overrides));
        $revision = $studio->submit($profile, $template);

        return app(TemplateReview::class)->approve($revision, static::admin())->fresh();
    }

    /**
     * An event whose invitation uses a marketplace template (as picking it in the wizard does).
     */
    public static function eventUsing(Template $template, ?User $owner = null, array $attributes = []): Event
    {
        $event = Payments::event($owner, $attributes);
        $event->invitation->update(['template_id' => $template->id, 'config' => $template->default_config]);

        return $event->fresh('invitation');
    }

    public static function listing(?User $user = null, array $data = [], bool $approve = true): VendorListing
    {
        $user ??= User::factory()->create();
        $listing = app(VendorListings::class)->save($user, $data + [
            'name_ar' => 'عدسة '.$user->name,
            'name_en' => 'Lens '.$user->name,
            'category' => 'photographer',
            'cities' => ['damascus'],
            'whatsapp' => '963944000111',
        ]);
        if ($approve) {
            app(VendorListings::class)->approve($listing, static::admin());
        }

        return $listing->fresh();
    }
}
