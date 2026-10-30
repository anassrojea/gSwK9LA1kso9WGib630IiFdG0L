<?php

namespace App\Services\Keepsakes;

use App\Models\Event;
use App\Services\InvitationConfig\InvitationConfig;
use App\Services\InvitationConfig\ThemeStyles;
use App\Services\OgImage\Chromium;
use App\Services\OgImage\FontEmbedder;
use App\View\InviteDate;
use Throwable;

/**
 * The highlight video's title card: the names, the occasion and the date in
 * the invitation's theme, as a PNG at the video's size. Rendered by the same
 * headless Chromium as the link previews so Arabic is shaped correctly
 * (resources/views/keepsakes/title-card.blade.php); without Chromium a plain
 * card in the theme colours with no text is used, so a build never fails on it.
 */
class TitleCard
{
    public function __construct(private Chromium $chromium, private FontEmbedder $fonts) {}

    /**
     * @return array{png: string, rendered: bool} rendered: false when the plain fallback was used
     */
    public function png(Event $event, int $width, int $height, int $timeout = 60): array
    {
        $config = InvitationConfig::upgrade($event->invitation?->config ?? []);

        if ($this->chromium->available() && $config !== []) {
            try {
                return ['png' => $this->chromium->screenshot($this->html($event, $config, $width, $height), $width, $height, $timeout), 'rendered' => true];
            } catch (Throwable $e) {
                report($e);
            }
        }

        return ['png' => $this->plain($config['theme']['colors'] ?? [], $width, $height), 'rendered' => false];
    }

    public function html(Event $event, array $config, int $width, int $height): string
    {
        $locale = $config['locale'] ?? 'ar';
        $names = InvitationConfig::section($config, 'names');
        $dateSection = InvitationConfig::section($config, 'date');
        $date = $dateSection ? InviteDate::fromIso($dateSection['start'], $locale) : null;
        $joiner = $locale === 'ar' ? ' و' : ' & ';

        return view('keepsakes.title-card', [
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'width' => $width,
            'height' => $height,
            'themeVars' => ThemeStyles::cssVariables($config['theme']),
            'fontFaces' => $this->fonts->css($config['theme']),
            'occasion' => trans('invite.occasion.'.($config['occasion'] ?? 'other'), [], $locale),
            'names' => $names ? trim($names['first'].(isset($names['second']) ? $joiner.$names['second'] : '')) : (string) $event->title,
            'date' => $date ? trim($date->weekday().' '.$date->day().' '.$date->monthYear()) : null,
            'hijri' => $date && ($dateSection['showHijri'] ?? false) ? ($dateSection['hijriOverride'] ?? $date->hijri()) : null,
        ])->render();
    }

    /**
     * @param  array<string, string>  $colors
     */
    private function plain(array $colors, int $width, int $height): string
    {
        [$r, $g, $b] = static::rgb($colors['bg'] ?? '#FBF6F2');
        [$ar, $ag, $ab] = static::rgb($colors['gold'] ?? '#C9A35C');

        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));
        $line = imagecolorallocate($image, $ar, $ag, $ab);
        $inset = (int) round(min($width, $height) * 0.05);
        imagesetthickness($image, max(2, (int) round($inset / 12)));
        imagerectangle($image, $inset, $inset, $width - $inset, $height - $inset, $line);

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
