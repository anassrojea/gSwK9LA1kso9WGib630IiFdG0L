<?php

namespace App\View;

use App\Services\Digits;
use App\Services\Hijri;
use Carbon\CarbonImmutable;
use IntlDateFormatter;

/**
 * The pieces of an event date the invitation shows, in the invitation's
 * locale and the event's own UTC offset (taken from the ISO string).
 */
final class InviteDate
{
    public function __construct(
        public readonly CarbonImmutable $at,
        public readonly string $locale,
    ) {}

    public static function fromIso(string $iso, string $locale): self
    {
        return new self(CarbonImmutable::parse($iso), $locale);
    }

    public function weekday(): string
    {
        return $this->format('EEEE');
    }

    public function day(): string
    {
        return Digits::localize($this->at->format('j'), $this->locale);
    }

    public function monthYear(): string
    {
        return Digits::localize($this->format('MMMM y'), $this->locale);
    }

    public function time(): string
    {
        return Digits::time($this->at->format('H:i'), $this->locale);
    }

    /** "١٠ . ١٢ . ٢٠٢٦" as on the envelope letter */
    public function short(): string
    {
        return Digits::localize($this->at->format('j . n . Y'), $this->locale);
    }

    public function hijri(): string
    {
        return Hijri::format($this->at, $this->locale, $this->icuTimezone());
    }

    public function googleCalendarUrl(string $title, ?string $location, int $hours = 4): string
    {
        $fmt = fn (CarbonImmutable $d) => $d->utc()->format('Ymd\THis\Z');

        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => $title,
            'dates' => $fmt($this->at).'/'.$fmt($this->at->addHours($hours)),
            'location' => $location,
        ]);
    }

    private function format(string $pattern): string
    {
        $formatter = new IntlDateFormatter(
            $this->locale === 'ar' ? 'ar' : 'en_GB',
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $this->icuTimezone(),
            IntlDateFormatter::GREGORIAN,
            $pattern,
        );

        return $formatter->format($this->at);
    }

    private function icuTimezone(): string
    {
        return 'GMT'.$this->at->format('P');
    }
}
