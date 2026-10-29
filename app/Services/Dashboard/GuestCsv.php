<?php

namespace App\Services\Dashboard;

use App\Models\Event;
use App\Services\Digits;
use App\Services\Wizard\GuestListParser;
use Carbon\CarbonImmutable;

/**
 * Guest lists as CSV, both ways.
 *
 * Import: columns name, seats, phone, tags, in that order or under a header
 * row in English or Arabic (الاسم، المقاعد، الهاتف، الوسوم). UTF-8 with or
 * without a BOM; files Excel saved as Windows-1256 are converted. Comma,
 * semicolon and tab separators all work. Every row is checked on its own,
 * so the host sees what is wrong before anything is imported.
 *
 * Export: UTF-8 with a BOM so Excel shows Arabic, headers in the
 * dashboard's language (the import reads them back).
 */
class GuestCsv
{
    public const MAX_ROWS = 1000;

    public const MAX_TAGS = 10;

    public const MAX_TAG = 30;

    public const COLUMNS = ['name', 'seats', 'phone', 'tags'];

    private const BOM = "\xEF\xBB\xBF";

    /** Header names per column, compared after normalize(). */
    private const HEADERS = [
        'name' => ['name', 'full name', 'guest', 'guests', 'family', 'household', 'الاسم', 'اسم', 'الاسم الكامل', 'اسم الضيف', 'الضيف', 'الضيوف', 'العائلة', 'اسم العائلة', 'المدعو', 'المدعوون'],
        'seats' => ['seats', 'seat', 'seats allowed', 'count', 'people', 'pax', 'guests count', 'المقاعد', 'مقاعد', 'عدد المقاعد', 'العدد', 'عدد', 'عدد الأشخاص', 'عدد الاشخاص', 'الأشخاص', 'الاشخاص', 'الأفراد', 'عدد الأفراد'],
        'phone' => ['phone', 'phone number', 'mobile', 'whatsapp', 'tel', 'telephone', 'الهاتف', 'هاتف', 'رقم الهاتف', 'الجوال', 'جوال', 'رقم الجوال', 'الموبايل', 'موبايل', 'واتساب', 'الواتساب', 'رقم الواتساب', 'الرقم', 'رقم'],
        'tags' => ['tags', 'tag', 'group', 'groups', 'labels', 'category', 'الوسوم', 'وسوم', 'الوسم', 'وسم', 'التصنيف', 'تصنيف', 'المجموعة', 'مجموعة', 'الفئة', 'فئة'],
    ];

    /**
     * @param  list<string>  $existingNames  names already on the list (for duplicate warnings)
     * @return array{rows: list<array{line: int, name: string, seats: int, phone: ?string, tags: list<string>, errors: list<string>, warnings: list<string>}>, header: bool, too_many: bool}
     */
    public static function parse(string $contents, array $existingNames = []): array
    {
        $records = static::records(static::toUtf8($contents));

        $header = false;
        $map = array_flip(self::COLUMNS); // column => index
        if ($records && ($found = static::headerMap($records[0][1]))) {
            $header = true;
            $map = $found;
            array_shift($records);
        }

        $known = array_fill_keys(array_map(fn ($n) => mb_strtolower(trim($n)), $existingNames), true);
        $seen = [];
        $rows = [];

        foreach ($records as [$line, $cells]) {
            $cell = fn (string $column) => isset($map[$column]) ? trim((string) ($cells[$map[$column]] ?? '')) : '';

            if (implode('', array_map('trim', $cells)) === '') {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                return ['rows' => $rows, 'header' => $header, 'too_many' => true];
            }

            $rows[] = static::row($line, $cell('name'), $cell('seats'), $cell('phone'), $cell('tags'), $known, $seen);
        }

        return ['rows' => $rows, 'header' => $header, 'too_many' => false];
    }

    /**
     * @param  array<string, true>  $known
     * @param  array<string, true>  $seen
     * @return array{line: int, name: string, seats: int, phone: ?string, tags: list<string>, errors: list<string>, warnings: list<string>}
     */
    private static function row(int $line, string $name, string $seats, string $phone, string $tags, array $known, array &$seen): array
    {
        $errors = [];
        $warnings = [];

        $name = static::unguard(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($name === '') {
            $errors[] = trans('app.dashboard.csv.errors.name_required');
        } elseif (mb_strlen($name) > GuestListParser::MAX_NAME) {
            $errors[] = trans('app.dashboard.csv.errors.name_long', ['max' => Digits::localize(GuestListParser::MAX_NAME)]);
        }

        $seatCount = 1;
        $seats = PhoneNumber::western(static::unguard($seats));
        if ($seats !== '') {
            if (! preg_match('/^\d{1,4}$/', $seats) || (int) $seats < 1 || (int) $seats > GuestListParser::MAX_SEATS) {
                $errors[] = trans('app.dashboard.csv.errors.seats', ['max' => Digits::localize(GuestListParser::MAX_SEATS)]);
            } else {
                $seatCount = (int) $seats;
            }
        }

        $phone = static::unguard($phone);
        if (! PhoneNumber::valid($phone)) {
            $errors[] = trans('app.dashboard.csv.errors.phone');
        }

        $tagList = static::tags(static::unguard($tags));
        if (count($tagList) > self::MAX_TAGS) {
            $errors[] = trans('app.dashboard.csv.errors.tags', ['max' => Digits::localize(self::MAX_TAGS)]);
        }

        $key = mb_strtolower($name);
        if ($name !== '' && (isset($known[$key]) || isset($seen[$key]))) {
            $warnings[] = trans('app.dashboard.csv.warnings.duplicate');
        }
        $seen[$key] = true;

        return [
            'line' => $line,
            'name' => mb_substr($name, 0, GuestListParser::MAX_NAME),
            'seats' => $seatCount,
            'phone' => PhoneNumber::valid($phone) ? PhoneNumber::normalize($phone) : $phone,
            'tags' => $tagList,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * "أقارب | أصدقاء", "friends, work", "أهل العريس، جيران"
     *
     * @return list<string>
     */
    public static function tags(string|array|null $value): array
    {
        $parts = is_array($value) ? $value : preg_split('/[|،,;؛]+/u', (string) $value);

        return collect($parts ?: [])
            ->map(fn ($t) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $t) ?? ''), 0, self::MAX_TAG))
            ->filter(fn ($t) => $t !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Column index per known column when the row is a header, else null.
     *
     * @param  list<string>  $cells
     * @return array<string, int>|null
     */
    private static function headerMap(array $cells): ?array
    {
        $map = [];
        foreach ($cells as $i => $cell) {
            $value = static::normalize($cell);
            foreach (self::HEADERS as $column => $names) {
                if (! isset($map[$column]) && in_array($value, $names, true)) {
                    $map[$column] = $i;
                    break;
                }
            }
        }

        return isset($map['name']) ? $map : null;
    }

    private static function normalize(string $header): string
    {
        $header = str_replace([self::BOM, "\u{200E}", "\u{200F}", 'ـ', '*', ':'], '', $header);
        $header = strtr($header, ['إ' => 'ا', 'آ' => 'ا']);

        return mb_strtolower(trim(preg_replace('/[\s_\-]+/u', ' ', $header) ?? $header));
    }

    /**
     * @return list<array{0: int, 1: list<string>}> [line number, cells]
     */
    private static function records(string $contents): array
    {
        $firstLine = strtok($contents, "\r\n") ?: '';
        $delimiter = collect([',', ';', "\t"])
            ->sortByDesc(fn ($d) => substr_count($firstLine, $d))
            ->first();

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        $records = [];
        $line = 0;
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($cells === [null]) {
                continue;
            }
            $records[] = [$line, array_map(fn ($c) => (string) $c, $cells)];
        }
        fclose($stream);

        return $records;
    }

    private static function toUtf8(string $contents): string
    {
        if (str_starts_with($contents, self::BOM)) {
            $contents = substr($contents, 3);
        } elseif (str_starts_with($contents, "\xFF\xFE") || str_starts_with($contents, "\xFE\xFF")) {
            $contents = mb_convert_encoding(substr($contents, 2), 'UTF-8', str_starts_with($contents, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }

        if (! mb_check_encoding($contents, 'UTF-8') && function_exists('iconv')) {
            // Excel on Arabic Windows saves "CSV" as Windows-1256
            $converted = @iconv('WINDOWS-1256', 'UTF-8//IGNORE', $contents);
            $contents = $converted !== false ? $converted : mb_scrub($contents, 'UTF-8');
        }

        return str_replace("\r\n", "\n", $contents);
    }

    /**
     * Cells written by export() may start with an apostrophe that stops
     * spreadsheet formulas; drop it again on the way in.
     */
    private static function unguard(string $value): string
    {
        $value = trim($value);

        return preg_match("/^'[=+\-@]/", $value) ? substr($value, 1) : $value;
    }

    /**
     * Stops a cell like "=HYPERLINK(...)" from running as a formula in Excel.
     */
    private static function guard(string|int|null $value): string|int|null
    {
        if (! is_string($value) || ! preg_match('/^[=+\-@\t\r]/', $value) || preg_match('/^\+?[\d\s]+$/', $value)) {
            return $value;
        }

        return "'".$value;
    }

    /**
     * Column headers of the exported file, in the dashboard's language.
     *
     * @return list<string>
     */
    public static function exportHeaders(?string $locale = null): array
    {
        return array_map(
            fn ($key) => (string) trans('app.dashboard.csv.columns.'.$key, [], $locale),
            ['name', 'seats', 'phone', 'tags', 'status', 'people', 'kids', 'table', 'message', 'meal', 'allergies', 'responded_at', 'last_opened_at', 'checked_in_at', 'link'],
        );
    }

    /**
     * Write the guest list (with replies) as CSV to $out.
     *
     * @param  resource  $out
     * @param  iterable<array<string, mixed>>  $rows  GuestBoard rows plus "meal" and "allergies"
     */
    public static function export($out, iterable $rows, string $timezone, ?string $locale = null): void
    {
        fwrite($out, self::BOM);
        fputcsv($out, static::exportHeaders($locale), ',', '"', '');

        $time = fn (?string $iso) => $iso ? CarbonImmutable::parse($iso)->setTimezone($timezone)->format('Y-m-d H:i') : '';

        foreach ($rows as $row) {
            fputcsv($out, array_map(static::guard(...), [
                $row['name'],
                $row['seats'],
                $row['phone'] ?? '',
                implode(' | ', $row['tags']),
                (string) trans('app.dashboard.status.'.$row['status'], [], $locale),
                $row['people'] ?? '',
                $row['kids'] ?? '',
                $row['table'] ?? '',
                $row['note'] ?? '',
                $row['meal'] ?? '',
                $row['allergies'] ?? '',
                $time($row['responded_at']),
                $time($row['last_opened_at']),
                $time($row['checked_in_at']),
                $row['link'],
            ]), ',', '"', '');
        }
    }

    public static function filename(Event $event): string
    {
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', (string) $event->title) ?: 'guests';

        return trim(mb_substr($slug, 0, 60), '-').'-guests.csv';
    }
}
