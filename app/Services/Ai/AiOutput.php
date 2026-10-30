<?php

namespace App\Services\Ai;

use App\Services\Dashboard\PhoneNumber;
use App\Services\Wizard\GuestListParser;

/**
 * Model output is never trusted as-is: each kind is cut down to what the
 * frozen v1 invitation schema (and the guest import) accepts. Returns null
 * when nothing usable is left.
 */
class AiOutput
{
    /** v1 schema: section_story.chapters */
    public const MAX_CHAPTERS = 12;

    public const MAX_TITLE = 80;

    public const MAX_TEXT = 2000;

    public const MAX_YEAR = 20;

    /** v1 schema: section_intro_line.text / caption */
    public const MAX_POEM = 2000;

    public const MAX_CAPTION = 80;

    public const MAX_FAMILIES = 1000;

    public const MAX_NOTES = 200;

    /**
     * @return array{chapters: list<array{year?: string, title: string, text: string}>}|null
     */
    public static function story(?array $data): ?array
    {
        $chapters = [];
        foreach (array_slice(is_array($data['chapters'] ?? null) ? $data['chapters'] : [], 0, self::MAX_CHAPTERS) as $chapter) {
            if (! is_array($chapter)) {
                continue;
            }
            $title = static::text($chapter['title'] ?? '', self::MAX_TITLE);
            $text = static::text($chapter['text'] ?? '', self::MAX_TEXT, keepLines: true);
            if ($title === '' || $text === '') {
                continue;
            }
            $year = static::text($chapter['year'] ?? '', self::MAX_YEAR);
            $chapters[] = ($year !== '' ? ['year' => $year] : []) + ['title' => $title, 'text' => $text];
        }

        return $chapters ? ['chapters' => $chapters] : null;
    }

    /**
     * @return array{text: string, caption?: string}|null
     */
    public static function poem(?array $data): ?array
    {
        $poem = static::text($data['poem'] ?? '', self::MAX_POEM, keepLines: true);
        if ($poem === '') {
            return null;
        }
        $caption = static::text($data['caption'] ?? '', self::MAX_CAPTION);

        return ['text' => $poem] + ($caption !== '' ? ['caption' => $caption] : []);
    }

    /**
     * Families ready for the import preview: names trimmed to the import's limit,
     * seats 1–100, phone numbers normalized (an invalid one is dropped and flagged).
     *
     * @return array{families: list<array{name: string, seats: int, phone: ?string, notes: ?string, warnings: list<string>}>}|null
     */
    public static function families(?array $data): ?array
    {
        $families = [];
        foreach (array_slice(is_array($data['families'] ?? null) ? $data['families'] : [], 0, self::MAX_FAMILIES) as $family) {
            if (! is_array($family)) {
                continue;
            }
            $name = static::text($family['name'] ?? '', GuestListParser::MAX_NAME);
            if ($name === '') {
                continue;
            }

            $seats = $family['seats'] ?? 1;
            $seats = is_numeric(PhoneNumber::western((string) $seats)) ? (int) PhoneNumber::western((string) $seats) : 1;
            $warnings = [];

            $phone = is_scalar($family['phone'] ?? null) ? trim((string) $family['phone']) : '';
            if ($phone !== '' && ! PhoneNumber::valid($phone)) {
                $warnings[] = 'phone';
                $phone = '';
            }

            $notes = static::text($family['notes'] ?? '', self::MAX_NOTES);
            $families[] = [
                'name' => $name,
                'seats' => max(1, min(GuestListParser::MAX_SEATS, $seats)),
                'phone' => $phone === '' ? null : PhoneNumber::normalize($phone),
                'notes' => $notes === '' ? null : $notes,
                'warnings' => $warnings,
            ];
        }

        return $families ? ['families' => $families] : null;
    }

    /** Plain text: no control characters or markup, at most $max characters (cut at a word when possible). */
    public static function text(mixed $value, int $max, bool $keepLines = false): string
    {
        if (! is_scalar($value)) {
            return '';
        }
        $text = strip_tags((string) $value);
        $text = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u', '', $text) ?? '';
        $text = $keepLines
            ? preg_replace(['/[ \t]+/u', '/\s*\R\s*/u'], [' ', "\n"], $text)
            : preg_replace('/\s+/u', ' ', $text);
        $text = trim((string) $text);

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $max * 0.6 ? mb_substr($cut, 0, $space) : $cut, " \n،,.-");
    }
}
