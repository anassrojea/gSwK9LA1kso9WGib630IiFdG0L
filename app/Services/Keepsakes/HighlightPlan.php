<?php

namespace App\Services\Keepsakes;

/**
 * How long each part of the highlight video lasts (config keepsakes.video):
 * a title card, the photos (Ken Burns), the guests' clips, the title card
 * again, with a crossfade between every two parts.
 *
 *   total = 2·title + photos·p + clips·c − (parts − 1)·fade
 *
 * p is chosen so the total lands on target_seconds, within photo_seconds
 * min/max; with few photos the video is simply shorter. Photos are dropped
 * (evenly) until the total is at most max_seconds.
 */
class HighlightPlan
{
    /**
     * @return array{photos: int, clips: int, photo: float, clip: float, title: float, fade: float, total: float}
     */
    public static function make(int $photos, int $clips): array
    {
        $c = config('keepsakes.video');
        $title = (float) $c['title_seconds'];
        $fade = (float) $c['fade_seconds'];
        $clip = (float) $c['clip_seconds'];
        $min = (float) $c['photo_seconds']['min'];
        $max = (float) $c['photo_seconds']['max'];
        $target = (float) $c['target_seconds'];
        $ceiling = (float) $c['max_seconds'];

        $clips = max(0, min($clips, (int) $c['max_clips']));
        $photos = max(0, min($photos, (int) $c['max_photos']));

        while (true) {
            $parts = 2 + $photos + $clips;
            $available = $target - 2 * $title - $clips * $clip + ($parts - 1) * $fade;
            $photo = $photos > 0 ? max($min, min($max, $available / $photos)) : 0.0;
            // a part must be longer than the fade into it
            $photo = $photos > 0 ? max($photo, $fade + 0.5) : 0.0;
            $total = 2 * $title + $photos * $photo + $clips * $clip - ($parts - 1) * $fade;

            if ($total <= $ceiling || $photos <= 1) {
                break;
            }
            $photos--;
        }

        return [
            'photos' => $photos,
            'clips' => $clips,
            'photo' => round($photo, 3),
            'clip' => $clip,
            'title' => $title,
            'fade' => $fade,
            'total' => round($total, 3),
        ];
    }
}
