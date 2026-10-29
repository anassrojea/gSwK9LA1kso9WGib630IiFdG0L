<?php

namespace App\Services\OgImage;

use App\Services\InvitationConfig\ThemeStyles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The theme's Google Fonts as @font-face rules with the font files inlined
 * (data: URIs), so the screenshot needs no network. Downloaded once per
 * font set and kept on the local disk (storage/app/og-fonts). Only the
 * Arabic and Latin subsets are kept. When the download fails the card falls
 * back to the system fonts (install fonts-noto-core on the server, see
 * docs/DEPLOY.md), and the next render tries again after a pause.
 */
class FontEmbedder
{
    public const SUBSETS = ['arabic', 'latin'];

    /** Google serves woff2 only to browsers it recognises. */
    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    public function css(array $theme): string
    {
        $url = ThemeStyles::fontsUrl($theme);
        if (! $url || ! config('og.embed_fonts')) {
            return '';
        }

        $key = sha1($url);
        $disk = Storage::disk('local');
        $file = "og-fonts/{$key}.css";

        if ($disk->exists($file)) {
            return (string) $disk->get($file);
        }
        if (Cache::has("og-fonts-failed:{$key}")) {
            return '';
        }

        try {
            $css = $this->download($url);
            $disk->put($file, $css);

            return $css;
        } catch (Throwable $e) {
            Log::warning('OG image fonts could not be downloaded; using system fonts.', ['url' => $url, 'error' => $e->getMessage()]);
            Cache::put("og-fonts-failed:{$key}", true, now()->addMinutes(10));

            return '';
        }
    }

    private function download(string $url): string
    {
        $css = Http::timeout(10)->withHeaders(['User-Agent' => self::USER_AGENT])->get($url)->throw()->body();

        // Google labels each block with its subset: /* arabic */ @font-face { … }
        preg_match_all('#/\*\s*([a-z0-9-]+)\s*\*/\s*(@font-face\s*\{[^}]*\})#i', $css, $labelled, PREG_SET_ORDER);
        $blocks = $labelled
            ? array_map(fn ($m) => $m[2], array_filter($labelled, fn ($m) => in_array(strtolower($m[1]), self::SUBSETS, true)))
            : (preg_match_all('#@font-face\s*\{[^}]*\}#i', $css, $all) ? $all[0] : []);

        $inline = fn (array $m) => 'url(data:font/woff2;base64,'.base64_encode(
            Http::timeout(15)->withHeaders(['User-Agent' => self::USER_AGENT])->get($m[1])->throw()->body()
        ).')';

        return implode("\n", array_map(
            fn (string $block) => preg_replace_callback('#url\((https://fonts\.gstatic\.com/[^)\s]+)\)#', $inline, $block),
            $blocks,
        ));
    }
}
