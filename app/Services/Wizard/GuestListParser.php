<?php

namespace App\Services\Wizard;

/**
 * A pasted guest list, one family per line (plan section 3, step 4):
 * "أم فيصل - 3", "Khaled Ali, 2", "عائلة الزين: ٤", "Sara (2)", "Omar x3".
 * Arabic-Indic and Persian digits count; a line without a number is 1 seat.
 * Twin of resources/js/lib/guest-list.ts, which previews the result live.
 */
class GuestListParser
{
    public const MAX_SEATS = 100;

    public const MAX_NAME = 120;

    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    // trailing seat count: an optional separator, then the number, then an optional unit word
    private const SEATS = '/^(?<name>.*?)[\s\x{200F}\x{200E}]*(?:[-–—,،:;|\/=]|\t)?\s*[(\[]?\s*(?:(?<=[\s(\[])[x×]\s*)?(?<!\d)(?<n>\d{1,3})\s*[)\]]?\s*'
        .'(?:seats?|people|persons?|pax|guests?|مقاعد|مقعد|أشخاص|اشخاص|شخص|أفراد|افراد|فرد|نفر|ضيوف)?\s*$/iu';

    /**
     * @return list<array{name: string, seats: int}>
     */
    public static function parse(string $text): array
    {
        $guests = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $guest = static::parseLine($line);
            if ($guest) {
                $guests[] = $guest;
            }
        }

        return $guests;
    }

    /**
     * @return array{name: string, seats: int}|null
     */
    public static function parseLine(string $line): ?array
    {
        $line = trim(strtr($line, self::DIGITS));
        // list bullets and numbering: "1. ", "2) ", "- ", "• "
        $line = preg_replace('/^(?:\d{1,3}[.)]\s+|[-•*·▪]\s*)/u', '', $line) ?? $line;

        $seats = 1;
        if (preg_match(self::SEATS, $line, $m) && static::clean($m['name']) !== '') {
            $line = $m['name'];
            $seats = max(1, min(self::MAX_SEATS, (int) $m['n']));
        }

        $name = static::clean($line);

        return $name === '' ? null : ['name' => mb_substr($name, 0, self::MAX_NAME), 'seats' => $seats];
    }

    private static function clean(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        // trim with a Unicode-aware pattern: trim() works on bytes and would cut Arabic letters
        return preg_replace('/^[\s\-–—,،:;|\/=()\[\]\x{200E}\x{200F}]+|[\s\-–—,،:;|\/=(\[\x{200E}\x{200F}]+$/u', '', $name) ?? $name;
    }
}
