<?php

namespace App\Services\Wallet;

use App\Models\Event;
use App\Services\InvitationConfig\ThemeStyles;
use App\Services\OgImage\Chromium;
use App\Services\OgImage\FontEmbedder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Images for a pass: the strip (the couple's names on the theme, rendered by
 * the link-preview Chromium so Arabic is shaped by a real browser, with a plain
 * GD fallback) and the icon. The strip is the same for every guest of an
 * event, cached at events/{event_id}/wallet/strip-{hash}.png.
 */
class PassImages
{
    /** Apple's event ticket strip at @2x; @1x is half. */
    public const STRIP_WIDTH = 750;

    public const STRIP_HEIGHT = 246;

    public function __construct(private Chromium $chromium, private FontEmbedder $fonts) {}

    /**
     * @return array<string, string> file name => PNG bytes
     */
    public function files(Event $event, PassData $data): array
    {
        $strip2x = $this->strip($event, $data);

        return [
            'icon.png' => $this->icon($data, 29),
            'icon@2x.png' => $this->icon($data, 58),
            'icon@3x.png' => $this->icon($data, 87),
            'strip.png' => static::resize($strip2x, self::STRIP_WIDTH / 2, self::STRIP_HEIGHT / 2),
            'strip@2x.png' => $strip2x,
        ];
    }

    public function strip(Event $event, PassData $data): string
    {
        $hash = substr(sha1(json_encode([1, $data->couple, $data->occasion, $data->theme, $data->locale], JSON_UNESCAPED_UNICODE)), 0, 16);
        $key = "events/{$event->getKey()}/wallet/strip-{$hash}.png";
        $disk = Storage::disk(config('filesystems.media_disk', 'public'));

        if ($disk->exists($key)) {
            return (string) $disk->get($key);
        }

        $png = null;
        if ($this->chromium->available()) {
            try {
                $png = $this->chromium->screenshot($this->stripHtml($data), self::STRIP_WIDTH, self::STRIP_HEIGHT, (int) config('og.timeout'));
            } catch (Throwable $e) {
                Log::warning('wallet: strip render failed, using the plain strip', ['event' => $event->getKey(), 'error' => $e->getMessage()]);
            }
        }
        $png ??= $this->plainStrip($data);
        $disk->put($key, $png);

        return $png;
    }

    public function stripHtml(PassData $data): string
    {
        return view('wallet.strip', [
            'locale' => $data->locale,
            'dir' => $data->rtl() ? 'rtl' : 'ltr',
            'themeVars' => ThemeStyles::cssVariables($data->theme),
            'fontFaces' => config('og.embed_fonts') ? $this->fonts->css($data->theme) : '',
            'occasion' => $data->occasion,
            'couple' => $data->couple,
            'width' => self::STRIP_WIDTH,
            'height' => self::STRIP_HEIGHT,
        ])->render();
    }

    /** The theme's colors with a thin gold frame, when Chromium is not available. */
    public function plainStrip(PassData $data): string
    {
        $image = imagecreatetruecolor(self::STRIP_WIDTH, self::STRIP_HEIGHT);
        [$r, $g, $b] = static::rgb($data->colors['background']);
        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));
        [$r, $g, $b] = static::rgb($data->colors['label']);
        $gold = imagecolorallocate($image, $r, $g, $b);
        imagesetthickness($image, 3);
        imagerectangle($image, 18, 18, self::STRIP_WIDTH - 19, self::STRIP_HEIGHT - 19, $gold);
        imagefilledellipse($image, (int) (self::STRIP_WIDTH / 2), (int) (self::STRIP_HEIGHT / 2), 26, 26, $gold);

        return static::png($image);
    }

    public function icon(PassData $data, int $size): string
    {
        $image = imagecreatetruecolor($size, $size);
        [$r, $g, $b] = static::rgb($data->colors['background']);
        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));
        [$r, $g, $b] = static::rgb($data->colors['label']);
        $gold = imagecolorallocate($image, $r, $g, $b);
        imagesetthickness($image, max(1, (int) round($size / 14)));
        imageellipse($image, (int) ($size / 2), (int) ($size / 2), (int) ($size * .62), (int) ($size * .62), $gold);
        imagefilledellipse($image, (int) ($size / 2), (int) ($size / 2), (int) ($size * .2), (int) ($size * .2), $gold);

        return static::png($image);
    }

    public static function resize(string $png, int $width, int $height): string
    {
        $source = imagecreatefromstring($png);
        $out = imagecreatetruecolor($width, $height);
        imagecopyresampled($out, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        imagedestroy($source);

        return static::png($out);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function rgb(string $hex): array
    {
        preg_match('/rgb\((\d+), (\d+), (\d+)\)/', PassData::rgb($hex), $m);

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    private static function png(\GdImage $image): string
    {
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
