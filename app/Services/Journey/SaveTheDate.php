<?php

namespace App\Services\Journey;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\User;
use App\Services\InvitationConfig\InvitationConfig;
use App\Services\Wizard\DraftCreator;
use App\Services\Wizard\EventSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Save the date (phase 8D, plan section 14.5): a cheap card months before the
 * invitation. It is an event whose invitation config has kind "save_the_date"
 * (names, date with Hijri, the city, "the invitation follows"), made from the
 * wizard's "احفظ الموعد" tile and sent through personal links or the open link.
 *
 *   - It has no RSVP: the wizard never offers one, and publishing refuses one.
 *   - Publishing needs the save_the_date product (feature "save_the_date"), not a plan:
 *     PlanAdvisor asks only for it, PublishGate checks it.
 *   - upgrade() turns it into the full invitation: a new draft event (source_event_id
 *     = the save-the-date) from the occasion's template, with the names, the date and
 *     the place carried over, and every family copied with a new personal link.
 */
class SaveTheDate
{
    public const KIND = 'save_the_date';

    public const FEATURE = 'save_the_date';

    /** Sections a save-the-date may not carry. */
    public const FORBIDDEN = ['rsvp', 'send_rose'];

    public static function is(array $config): bool
    {
        return InvitationConfig::kind($config) === self::KIND;
    }

    public static function isEvent(Event $event): bool
    {
        return $event->invitation && static::is($event->invitation->config ?? []);
    }

    /**
     * Reasons a save-the-date config can't be published as it is.
     *
     * @return list<string>
     */
    public static function problems(array $config): array
    {
        $types = array_column($config['sections'] ?? [], 'type');

        return array_intersect($types, self::FORBIDDEN) !== [] ? ['save_the_date_rsvp'] : [];
    }

    /**
     * The full invitation, as a new draft event carrying the save-the-date's
     * names, date, place and families. Upgrading twice returns the first upgrade.
     */
    public function upgrade(Event $saveTheDate, User $user, string $occasion): Event
    {
        $source = $saveTheDate->invitation;
        if (! $source || ! static::is($source->config ?? [])) {
            throw ValidationException::withMessages(['event' => trans('journey.save_the_date.errors.not_save_the_date')]);
        }
        if (! in_array($occasion, array_diff(Event::WIZARD_OCCASIONS, ['save_the_date']), true)) {
            throw ValidationException::withMessages(['occasion' => trans('validation.in', ['attribute' => 'occasion'])]);
        }
        if ($existing = Event::query()->where('source_event_id', $saveTheDate->getKey())->first()) {
            return $existing;
        }

        $from = InvitationConfig::upgrade($source->config);
        $template = DraftCreator::defaultTemplate($occasion);
        $config = DraftCreator::configFor($occasion, $template);
        $config = static::carryOver($from, $config);

        return DB::transaction(function () use ($saveTheDate, $user, $occasion, $template, $config) {
            $event = new Event([
                'occasion' => $occasion,
                'status' => 'draft',
                'owner_id' => $saveTheDate->owner_id ?? $user->getKey(),
                'org_id' => $saveTheDate->org_id,
                'timezone' => $saveTheDate->timezone,
            ]);
            $event->source_event_id = $saveTheDate->getKey();
            EventSync::apply($event, $config);
            $event->save();

            Invitation::query()->create([
                'event_id' => $event->getKey(),
                'template_id' => $template?->getKey(),
                'config' => $config,
                'published_at' => null,
            ]);

            // every family, with a new personal link (the save-the-date's links keep showing the card)
            $saveTheDate->households()->orderBy('created_at')->each(function (Household $h) use ($event) {
                Household::query()->create([
                    'event_id' => $event->getKey(),
                    'display_name' => $h->display_name,
                    'phone' => $h->phone,
                    'seats_allowed' => $h->seats_allowed,
                    'section' => $h->section,
                    'tags' => $h->tags,
                ]);
            });

            AuditLog::record('save_the_date.upgraded', $saveTheDate, ['event' => $event->getKey(), 'occasion' => $occasion, 'households' => $saveTheDate->households()->count()], $user);

            return $event;
        });
    }

    /**
     * The save-the-date's names, date and place written into the invitation's own sections.
     */
    public static function carryOver(array $from, array $config): array
    {
        foreach (['names', 'date', 'place'] as $type) {
            $source = InvitationConfig::section($from, $type);
            if (! $source) {
                continue;
            }
            $found = false;
            foreach ($config['sections'] as $i => $section) {
                if ($section['type'] !== $type) {
                    continue;
                }
                $found = true;
                $config['sections'][$i] = match ($type) {
                    'names' => array_filter(['first' => $source['first'], 'second' => $source['second'] ?? null], fn ($v) => $v !== null) + array_diff_key($section, ['second' => true]) + ['type' => 'names'],
                    'date' => ['start' => $source['start']] + array_intersect_key($source, ['showHijri' => true, 'hijriOverride' => true]) + $section,
                    // a save-the-date gives the city: it becomes the address until the host names the hall
                    'place' => ['address' => $source['name']] + array_diff_key($section, ['lat' => true, 'lng' => true, 'mapUrl' => true]),
                };
            }
            if (! $found && $type !== 'place') {
                $config['sections'][] = $source;
            }
        }

        return $config;
    }
}
