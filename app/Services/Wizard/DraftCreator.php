<?php

namespace App\Services\Wizard;

use App\Models\Event;
use App\Models\HallLayout;
use App\Models\Invitation;
use App\Models\Template;
use App\Models\User;
use App\Models\VenueProfile;
use App\Services\DraftAccess;
use App\Services\Venues\VenueDirectory;
use Illuminate\Support\Facades\DB;

/**
 * POST /create: a draft event plus its unpublished invitation, filled from a
 * template so the host never starts from an empty page (plan section 3).
 */
class DraftCreator
{
    /** Occasions about two people: the names section keeps a second name and the story. */
    public const COUPLE_OCCASIONS = ['wedding', 'engagement', 'henna'];

    public function __construct(private DraftAccess $access) {}

    /**
     * @param  HallLayout|null  $venueHall  a public hall of a listed venue (phase 8B): linked to
     *                                      the event, and the invitation's place becomes the venue
     */
    public function create(string $occasion, ?User $user, ?Template $template = null, ?HallLayout $venueHall = null): Event
    {
        $template ??= static::defaultTemplate($occasion);
        $config = static::configFor($occasion, $template);
        if ($venueHall?->relationLoaded('venueProfile') && $venueHall->getRelation('venueProfile')) {
            $config = static::withVenue($config, $venueHall->getRelation('venueProfile'));
        }

        return DB::transaction(function () use ($occasion, $user, $template, $config, $venueHall) {
            $event = new Event(['occasion' => $occasion, 'status' => 'draft']);
            EventSync::apply($event, $config);
            if ($venueHall) {
                $event->hall_layout_id = $venueHall->getKey();
            }

            if ($user) {
                $event->owner_id = $user->getKey();
            } else {
                $event->id = $event->newUniqueId();
                $this->access->claimForSession($event);
            }
            $event->save();

            Invitation::query()->create([
                'event_id' => $event->getKey(),
                'template_id' => $template?->getKey(),
                'config' => $config,
                'published_at' => null,
            ]);

            return $event;
        });
    }

    /**
     * The place section points at the venue: its name, district and city, map link and coordinates.
     */
    public static function withVenue(array $config, VenueProfile $venue): array
    {
        $locale = $config['locale'] ?? 'ar';
        foreach ($config['sections'] ?? [] as $i => $section) {
            if (($section['type'] ?? null) !== 'place') {
                continue;
            }
            $place = ['type' => 'place'] + array_intersect_key($section, array_flip(['hidden', 'label']));
            $place['name'] = mb_substr($venue->displayName($locale) ?: $section['name'], 0, 120);
            $address = trim(implode($locale === 'ar' ? '، ' : ', ', array_filter([$venue->area, trans("venues.cities.{$venue->city}", [], $locale)])));
            if ($address !== '') {
                $place['address'] = mb_substr($address, 0, 300);
            }
            if ($venue->lat !== null && $venue->lng !== null) {
                $place['lat'] = $venue->lat;
                $place['lng'] = $venue->lng;
            }
            if ($map = VenueDirectory::mapUrl($venue)) {
                $place['mapUrl'] = mb_substr($map, 0, 500);
            }
            $config['sections'][$i] = $place;
        }

        return $config;
    }

    /**
     * The first active template for the occasion, else the first active template at all.
     */
    public static function defaultTemplate(string $occasion): ?Template
    {
        $query = Template::query()->where('active', true)->orderBy('sort')->orderBy('id');

        return (clone $query)->where('occasion', $occasion)->first() ?? $query->first();
    }

    /**
     * The template's default config with the occasion set. A template made for
     * another occasion is adapted so its sample text still makes sense.
     */
    public static function configFor(string $occasion, ?Template $template): array
    {
        $config = $template?->default_config ?? static::fallbackConfig();

        if (($config['occasion'] ?? null) === $occasion) {
            return $config;
        }

        $locale = $config['locale'] ?? 'ar';
        $couple = in_array($occasion, self::COUPLE_OCCASIONS, true);
        $config['occasion'] = $occasion;

        foreach ($config['sections'] as $i => $section) {
            $section = match ($section['type']) {
                'names' => $couple
                    ? array_diff_key($section, ['age' => true]) + ['second' => trans('app.wizard.defaults.second_name', [], $locale)]
                    : array_filter([
                        'type' => 'names',
                        'hidden' => $section['hidden'] ?? null,
                        'first' => trans("app.wizard.defaults.names.{$occasion}", [], $locale),
                        'age' => $occasion === 'birthday' ? 7 : null,
                    ], fn ($v) => $v !== null),
                'hosts' => ['outro' => trans("app.wizard.defaults.outro.{$occasion}", [], $locale)] + $section,
                'story' => $couple ? $section : ['hidden' => true] + $section,
                default => $section,
            };
            $config['sections'][$i] = $section;
        }

        return $config;
    }

    /**
     * Used only when the catalog is empty (fresh install before seeding).
     */
    public static function fallbackConfig(): array
    {
        return [
            'version' => 1,
            'occasion' => 'other',
            'locale' => 'ar',
            'opening' => ['id' => 'envelope'],
            'theme' => [
                'id' => 'wine-gold',
                'colors' => ['bg' => '#FBF6EF', 'ink' => '#3A1F24', 'accent' => '#7A1F35', 'soft' => '#8A6E6E', 'gold' => '#C9A35C'],
                'fonts' => ['display' => 'Aref Ruqaa', 'body' => 'Amiri', 'label' => 'Almarai'],
            ],
            'sections' => [
                ['type' => 'names', 'first' => trans('app.wizard.defaults.names.other', [], 'ar')],
                ['type' => 'date', 'start' => now('Asia/Riyadh')->addMonths(2)->setTime(20, 0)->toIso8601String(), 'showHijri' => true, 'countdown' => true],
                ['type' => 'place', 'name' => trans('app.wizard.defaults.place', [], 'ar')],
                ['type' => 'rsvp'],
            ],
        ];
    }
}
