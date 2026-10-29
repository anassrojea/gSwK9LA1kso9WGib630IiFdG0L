<?php

namespace App\Services\Wizard;

use App\Models\Event;
use App\Services\InvitationConfig\InvitationConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The events table mirrors a few facts from the invitation config, for the
 * dashboard, reminders and queries that should not dig into JSON: the title
 * (from the names section), starts_at (date section) and the venue (place section).
 */
class EventSync
{
    public static function apply(Event $event, array $config): Event
    {
        $names = InvitationConfig::section($config, 'names');
        $date = InvitationConfig::section($config, 'date');
        $place = InvitationConfig::section($config, 'place');

        $event->occasion = $config['occasion'];
        $event->title = static::title($config['occasion'], $names, $config['locale']);
        // stored in UTC: the column format drops the offset
        $event->starts_at = $date ? CarbonImmutable::parse($date['start'])->utc() : null;
        $event->venue_name = $place ? Str::limit($place['name'], 250, '') : null;
        $event->address = isset($place['address']) ? Str::limit($place['address'], 250, '') : null;
        $event->lat = $place['lat'] ?? null;
        $event->lng = $place['lng'] ?? null;

        return $event;
    }

    /**
     * "زفاف ريم وعمر", "Sara's birthday"…
     */
    public static function title(string $occasion, ?array $names, string $locale): string
    {
        $joined = $names
            ? trim($names['first'].(isset($names['second']) ? ($locale === 'ar' ? ' و' : ' & ').$names['second'] : ''))
            : '';

        $key = $joined === '' ? "app.wizard.occasions.{$occasion}" : "app.wizard.event_title.{$occasion}";

        return Str::limit(trans($key, ['names' => $joined], $locale), 250, '');
    }
}
