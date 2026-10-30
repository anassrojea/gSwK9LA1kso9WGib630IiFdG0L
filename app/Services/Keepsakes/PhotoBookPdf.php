<?php

namespace App\Services\Keepsakes;

use App\Models\Media;
use App\Models\PhotoBook;
use App\Services\Album\Album;
use App\Services\InvitationConfig\InvitationConfig;
use App\Services\InvitationConfig\ThemeStyles;
use App\Services\OgImage\FontEmbedder;
use App\Services\Pdf\PdfRenderer;
use App\View\InviteDate;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The photo book's print-ready PDF (phase 8D), printed by headless Chromium
 * (App\Services\Pdf\PdfRenderer) from resources/views/keepsakes/photo-book.blade.php:
 *
 *   page size  trim (A4 landscape 297×210 mm or 21×21 cm) + bleed on every side
 *              + the slug that carries the crop marks (config keepsakes.photo_book)
 *   images     the album originals, resampled down (never up) to `dpi` at the size
 *              they are printed, as JPEG files beside the page (file:// URLs, nothing
 *              leaves the machine)
 *   pages      exactly 20 or 40, from PhotoBooks::layout
 *
 * Stored on the media disk at events/{id}/photobook/, so the storage period
 * deletes it with the album.
 */
class PhotoBookPdf
{
    public function __construct(private PdfRenderer $renderer, private PhotoBooks $books, private Album $album, private FontEmbedder $fonts) {}

    public function available(): bool
    {
        return $this->renderer->available();
    }

    /**
     * Page geometry in millimetres.
     *
     * @return array{trim_w: float, trim_h: float, bleed: float, slug: float, page_w: float, page_h: float, marks: bool}
     */
    public static function geometry(string $size): array
    {
        $c = config('keepsakes.photo_book');
        $trim = $c['sizes'][$size] ?? $c['sizes']['a4_landscape'];
        $bleed = (float) $c['bleed_mm'];
        $marks = (bool) $c['crop_marks'];
        $slug = $marks ? (float) $c['slug_mm'] : 0.0;

        return [
            'trim_w' => (float) $trim['width'],
            'trim_h' => (float) $trim['height'],
            'bleed' => $bleed,
            'slug' => $slug,
            'page_w' => $trim['width'] + 2 * ($bleed + $slug),
            'page_h' => $trim['height'] + 2 * ($bleed + $slug),
            'marks' => $marks,
        ];
    }

    /**
     * Render the PDF and store it; returns the stored key.
     */
    public function generate(PhotoBook $book): string
    {
        if (! $this->available()) {
            throw new RuntimeException('Chromium not found: set OG_CHROME_PATH.');
        }

        $dir = rtrim(sys_get_temp_dir(), '/').'/daawa-book-'.Str::random(12);
        File::ensureDirectoryExists($dir, 0700);

        try {
            $html = $this->html($book, $dir);
            $pdf = $this->renderer->render($html, (int) config('keepsakes.photo_book.timeout', 300));

            $disk = $this->album->disk();
            $key = "events/{$book->event_id}/photobook/book-{$book->getKey()}-".now()->format('YmdHis').'.pdf';
            foreach ($disk->files("events/{$book->event_id}/photobook") as $old) {
                if (str_contains($old, $book->getKey())) {
                    $disk->delete($old);
                }
            }
            $disk->put($key, $pdf);

            $book->forceFill(['pdf_key' => $key, 'pdf_pages' => static::pageCount($pdf), 'pdf_at' => now()])->save();

            return $key;
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * The book as one HTML document; its images are written into $dir.
     */
    public function html(PhotoBook $book, string $dir): string
    {
        $book->loadMissing('event.invitation');
        $geometry = static::geometry($book->size);
        $layout = PhotoBooks::layout($book->pages, $this->books->chosen($book));
        $config = InvitationConfig::upgrade($book->event->invitation?->config ?? []);
        $locale = $config['locale'] ?? 'ar';
        $dateSection = $config ? InvitationConfig::section($config, 'date') : null;
        $date = $dateSection ? InviteDate::fromIso($dateSection['start'], $locale) : null;

        // one full-bleed page wide at the print resolution: never more pixels than that
        $maxPx = (int) ceil(($geometry['trim_w'] + 2 * $geometry['bleed']) / 25.4 * (int) config('keepsakes.photo_book.dpi', 300));
        $files = [];
        foreach ($layout as $p => $page) {
            foreach ($page['photos'] as $i => $media) {
                $files[$p][$i] = $this->image($media, "{$dir}/p{$p}-{$i}.jpg", $page['kind'] === 'double' ? intdiv($maxPx, 2) + 1 : $maxPx);
            }
        }

        return view('keepsakes.photo-book', [
            'book' => $book,
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'g' => $geometry,
            'layout' => $layout,
            'files' => $files,
            'themeVars' => $config ? ThemeStyles::cssVariables($config['theme']) : '',
            'fontFaces' => $config ? $this->fonts->css($config['theme']) : '',
            'date' => $date ? trim($date->day().' '.$date->monthYear()) : null,
            'occasion' => trans('invite.occasion.'.($config['occasion'] ?? 'other'), [], $locale),
        ])->render();
    }

    /**
     * Pages in a PDF ("/Type /Page" objects; "/Pages" is the tree).
     */
    public static function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $pdf);
    }

    /**
     * @return list<array{0: float, 1: float}> each page's MediaBox width and height in points
     */
    public static function mediaBoxes(string $pdf): array
    {
        preg_match_all('#/MediaBox\s*\[\s*([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s*\]#', $pdf, $m, PREG_SET_ORDER);

        return array_map(fn (array $box) => [(float) $box[3] - (float) $box[1], (float) $box[4] - (float) $box[2]], $m);
    }

    /**
     * The original, resampled down to at most $maxPx on its long side, as a JPEG file.
     */
    private function image(Media $media, string $path, int $maxPx): ?string
    {
        $disk = $this->album->disk();
        if (! $disk->exists($media->storage_key)) {
            return null;
        }

        $source = @imagecreatefromstring((string) $disk->get($media->storage_key));
        if (! $source) {
            return null;
        }

        [$w, $h] = [imagesx($source), imagesy($source)];
        $scale = min(1.0, $maxPx / max($w, $h));
        if ($scale < 1.0) {
            $resized = imagescale($source, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)), IMG_BICUBIC);
            imagedestroy($source);
            $source = $resized;
        }

        imagejpeg($source, $path, (int) config('keepsakes.photo_book.jpeg_quality', 90));
        imagedestroy($source);

        return 'file://'.$path;
    }
}
