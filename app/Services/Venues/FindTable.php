<?php

namespace App\Services\Venues;

use App\Models\Event;
use App\Models\Household;
use App\Services\Entitlements;
use App\Services\EventDay;
use App\Services\Invite\GuestDayState;
use App\Services\Seating\HallLayoutData;
use App\Services\Seating\SeatingPlan;
use Illuminate\Support\Collection;

/**
 * "Find your table" at the entrance (phase 8B, plan section 9 item 2, part of
 * "Smart Hall"): a guest types their name or phone at /e/{slug}/find and sees
 * their table on the hall map. Only on the event day (EventDay window), only
 * with the seating feature, and it never lists or names guests:
 *
 *  - a phone number matches when its last 9 digits equal one household's phone;
 *  - a name matches with the last 4 digits of that household's phone: the
 *    folded name (case, diacritics, alef/ya/ta marbuta variants) equal, or
 *    within a small edit distance;
 *  - a name alone matches only a household without a phone, and only exactly.
 *
 * Anything but exactly one household is "not found", so the answer never
 * tells whether a name is on the list. Rate limits: routes/invite.php
 * (find-table, VenueServiceProvider).
 */
class FindTable
{
    public const STATE_OPEN = 'open';

    public const STATE_CLOSED = 'closed';

    public const STATE_UNAVAILABLE = 'unavailable';

    public function __construct(private Entitlements $entitlements) {}

    public function state(Event $event): string
    {
        if (! $this->entitlements->hasFeature($event, Entitlements::SEATING) || ! $event->hall_layout_id) {
            return self::STATE_UNAVAILABLE;
        }

        return EventDay::isLive($event) ? self::STATE_OPEN : self::STATE_CLOSED;
    }

    /**
     * @return array{found: false}|array{found: true, seated: false}|array{found: true, seated: true, table: string, map: ?array}
     */
    public function lookup(Event $event, ?string $name, ?string $phone, ?string $last4): array
    {
        $household = $this->match($event, $name, $phone, $last4);
        if (! $household) {
            return ['found' => false];
        }

        $tableId = SeatingPlan::tableOf($household);
        if (! $tableId) {
            return ['found' => true, 'seated' => false];
        }
        $layout = $event->hallLayout?->layout;

        return [
            'found' => true,
            'seated' => true,
            'table' => HallLayoutData::tableNumber($tableId),
            'map' => $layout ? GuestDayState::map($layout, $tableId) : null,
        ];
    }

    public function match(Event $event, ?string $name, ?string $phone, ?string $last4): ?Household
    {
        $digits = static::digits((string) $phone);
        $last4 = static::digits((string) $last4);
        $folded = static::fold((string) $name);

        $households = Household::query()->where('event_id', $event->getKey())->get(['id', 'event_id', 'display_name', 'phone']);

        if (strlen($digits) >= 8) {
            $tail = substr($digits, -9);

            return static::only($households->filter(fn (Household $h) => ($p = static::digits((string) $h->phone)) !== '' && substr($p, -9) === $tail));
        }

        if (mb_strlen($folded) < 2) {
            return null;
        }

        if (strlen($last4) === 4) {
            return static::only($households->filter(function (Household $h) use ($folded, $last4) {
                $p = static::digits((string) $h->phone);

                return strlen($p) >= 4 && substr($p, -4) === $last4 && static::similar($folded, static::fold($h->display_name));
            }));
        }

        // a name alone: only households without a phone, exact name only
        return static::only($households->filter(fn (Household $h) => static::digits((string) $h->phone) === '' && static::fold($h->display_name) === $folded));
    }

    /**
     * Arabic-aware folding (same idea as resources/js/lib/seating/plan.ts fold()):
     * lower case, no diacritics or tatweel, one alef, ya and ha, single spaces,
     * and "عائلة" / "family" / "آل" prefixes ignored.
     */
    public static function fold(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        $text = (string) preg_replace('/^(عائله|عايله|اسره|ال|the|family|mr|mrs)\s+/u', '', trim($text));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Equal, or a few typos apart (about one edit per six letters, at least one). */
    public static function similar(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $la = mb_strlen($a);
        $lb = mb_strlen($b);
        $max = max(1, intdiv(max($la, $lb), 6));
        if (abs($la - $lb) > $max) {
            return false;
        }

        return static::distance($a, $b) <= $max;
    }

    /** Levenshtein distance over characters (multibyte safe). */
    public static function distance(string $a, string $b): int
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        $prev = range(0, count($y));
        foreach ($x as $i => $cx) {
            $cur = [$i + 1];
            foreach ($y as $j => $cy) {
                $cur[$j + 1] = min($prev[$j + 1] + 1, $cur[$j] + 1, $prev[$j] + ($cx === $cy ? 0 : 1));
            }
            $prev = $cur;
        }

        return $prev[count($y)];
    }

    public static function digits(string $value): string
    {
        $value = strtr($value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);

        return (string) preg_replace('/\D+/', '', $value);
    }

    /**
     * @param  Collection<int, Household>  $matches
     */
    private static function only($matches): ?Household
    {
        return $matches->count() === 1 ? $matches->first() : null;
    }
}
