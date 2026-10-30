<?php

namespace App\Services\Wallet;

use App\Http\Controllers\Invite\WallController;
use App\Models\Household;
use App\Services\Digits;
use App\Services\Entitlements;
use App\Services\InvitationConfig\InvitationConfig;
use App\View\InviteDate;

/**
 * What a household's pass shows, the same on every platform (plan section 10):
 * the event, the family, date and time, the hall, the table (when seated),
 * the seats and a QR of the personal link; the colors come from the theme.
 */
final class PassData
{
    public function __construct(
        public readonly string $locale,
        public readonly string $eventName,
        public readonly string $occasion,
        public readonly string $couple,
        public readonly string $guest,
        public readonly ?string $date,
        public readonly ?string $time,
        /** ISO 8601 with the event's offset */
        public readonly ?string $start,
        public readonly ?string $hall,
        public readonly ?string $address,
        public readonly ?float $lat,
        public readonly ?float $lng,
        public readonly ?string $table,
        public readonly int $seats,
        public readonly string $url,
        /** hex colors: background, foreground, label */
        public readonly array $colors,
        public readonly array $theme,
    ) {}

    public static function for(Household $household, Entitlements $entitlements): self
    {
        $household->loadMissing(['event.invitation', 'rsvp']);
        $event = $household->event;
        $config = $event->invitation->renderConfig();
        $locale = $config['locale'];

        $dateSection = InvitationConfig::section($config, 'date');
        $date = $dateSection ? InviteDate::fromIso($dateSection['start'], $locale) : null;
        $place = InvitationConfig::section($config, 'place');

        $table = $entitlements->hasFeature($event, Entitlements::SEATING)
            ? $household->seatAssignments()->value('table_id')
            : null;

        $colors = $config['theme']['colors'] ?? [];
        $hex = fn (?string $c, string $fallback) => is_string($c) && preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $c) ? $c : $fallback;
        $occasion = trans('invite.occasion.'.$config['occasion'], [], $locale);
        $couple = WallController::couple($config, $event->title);

        return new self(
            locale: $locale,
            eventName: trim($occasion.' '.$couple),
            occasion: $occasion,
            couple: $couple,
            guest: $household->display_name,
            date: $date ? trim($date->weekday().' '.$date->day().' '.$date->monthYear()) : null,
            time: $date?->time(),
            start: $date?->at->toIso8601String(),
            hall: $place['name'] ?? $event->venue_name,
            address: $place['address'] ?? $event->address,
            lat: isset($place['lat']) ? (float) $place['lat'] : $event->lat,
            lng: isset($place['lng']) ? (float) $place['lng'] : $event->lng,
            table: $table ? Digits::localize(preg_replace('/^T/i', '', (string) $table), $locale) : null,
            seats: $household->rsvp?->attending ? max(1, (int) $household->rsvp->people_count) : $household->seats_allowed,
            url: rtrim((string) config('app.invite_url'), '/').route('invite.show', $household->token, false),
            colors: [
                'background' => $hex($colors['accent'] ?? null, '#7A2E3A'),
                'foreground' => $hex($colors['onAccent'] ?? null, '#FFFFFF'),
                'label' => $hex($colors['goldLight'] ?? $colors['gold'] ?? null, '#F3E2C0'),
            ],
            theme: $config['theme'],
        );
    }

    public function rtl(): bool
    {
        return $this->locale === 'ar';
    }

    /** What the issued pass showed: a change means the pass needs an update. */
    public function contentHash(): string
    {
        return sha1(json_encode([$this->eventName, $this->guest, $this->start, $this->hall, $this->table, $this->seats], JSON_UNESCAPED_UNICODE));
    }

    public function seatsLabel(): string
    {
        return trans_choice('invite.rsvp.person', $this->seats, ['count' => Digits::localize($this->seats, $this->locale)], $this->locale);
    }

    /** "rgb(122, 46, 58)" for pass.json */
    public static function rgb(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return sprintf('rgb(%d, %d, %d)', hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }
}
