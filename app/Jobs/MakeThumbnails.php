<?php

namespace App\Jobs;

use App\Events\MediaUploaded;
use App\Models\Media;
use App\Services\Album\Album;
use GdImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Thumbnails for an album upload (plan section 11), made with GD as WebP (JPEG
 * where GD has no WebP support): 400 px
 * (thumb_key, grids and the wall's strip) and 1200 px (preview_key, the
 * lightbox and the wall). A video uses the poster frame its browser sent.
 * When the item is visible, the live wall hears about it (MediaUploaded).
 */
class MakeThumbnails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public string $mediaId) {}

    public function handle(Album $album): void
    {
        $media = Media::query()->find($this->mediaId);
        if (! $media) {
            return;
        }

        $disk = $album->disk();
        $source = $media->kind === 'photo' ? $media->storage_key : $media->thumb_key;

        if ($source && $disk->exists($source) && ($image = static::load((string) $disk->get($source)))) {
            $media->width ??= $media->kind === 'photo' ? imagesx($image) : null;
            $media->height ??= $media->kind === 'photo' ? imagesy($image) : null;

            $keys = [];
            foreach (config('album.thumbs') as $name => $size) {
                $key = $album->thumbPath($media, $size);
                $disk->put($key, static::encode(static::fit($image, $size), (int) config('album.thumb_quality')));
                $keys[$name] = $key;
            }
            imagedestroy($image);

            if ($media->kind === 'video' && $source !== $keys['thumb']) {
                $disk->delete($source);
            }
            $media->thumb_key = $keys['thumb'];
            $media->preview_key = $keys['preview'];
            $media->save();
        } elseif ($media->kind === 'photo') {
            Log::warning('album: could not read an upload for thumbnails', ['media' => $media->getKey()]);
        }

        if (! $media->hidden && $media->source === Media::SOURCE_GUEST) {
            MediaUploaded::dispatch($media);
        }
    }

    /**
     * Decode a JPEG / PNG / WebP and turn it upright (EXIF orientation from phone cameras).
     */
    public static function load(string $bytes): ?GdImage
    {
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            return null;
        }

        if (function_exists('exif_read_data') && str_starts_with($bytes, "\xFF\xD8")) {
            $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
            $rotated = match ((int) ($exif['Orientation'] ?? 1)) {
                3 => imagerotate($image, 180, 0),
                6 => imagerotate($image, -90, 0),
                8 => imagerotate($image, 90, 0),
                default => null,
            };
            if ($rotated) {
                imagedestroy($image);
                $image = $rotated;
            }
        }

        return $image;
    }

    /** Scale down so the longer edge is at most $edge (never up). */
    public static function fit(GdImage $image, int $edge): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $edge / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $image, 0, 0, 0, 0, $w, $h, $width, $height);

        return $out;
    }

    /** WebP when this PHP's GD can write it, else JPEG (Album::thumbExtension). */
    public static function encode(GdImage $image, int $quality): string
    {
        ob_start();
        Album::thumbExtension() === 'webp' ? imagewebp($image, null, $quality) : imagejpeg($image, null, $quality);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
