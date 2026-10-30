<?php

/*
| After the event (phase 8D, plan sections 5, 13.2 and 14.5): the thank-you
| card, the highlight video and the printed photo book. Files go on
| config('filesystems.media_disk') under events/{event_id}/..., so the
| storage-expiry job (events:expire-storage) deletes them with the album.
| See App\Services\Keepsakes and docs/AFTER_EVENT.md.
*/

return [

    'thank_you' => [
        // /i/{token} shows the thank-you card instead of the invitation this long after the event starts
        'show_after_hours' => (int) env('THANK_YOU_SHOW_AFTER_HOURS', 12),
        // photos offered when the card is first made
        'default_photos' => 9,
        'max_photos' => 24,
    ],

    'video' => [
        // the ffmpeg binary; empty: "ffmpeg" on the PATH. The job fails cleanly without one.
        'ffmpeg' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
        'ffprobe' => env('FFPROBE_PATH'),
        // seconds one whole build may take (every format), and the largest file kept
        'timeout' => (int) env('HIGHLIGHT_TIMEOUT', 900),
        'max_mb' => (int) env('HIGHLIGHT_MAX_MB', 200),
        // target length; fewer photos make a shorter video, never a longer one
        'target_seconds' => (int) env('HIGHLIGHT_TARGET_SECONDS', 75),
        'min_seconds' => 60,
        'max_seconds' => 90,
        // per photo, before crossfades (the target decides within these bounds)
        'photo_seconds' => ['min' => 2.5, 'max' => 6.0],
        'title_seconds' => 4.0,
        'fade_seconds' => 0.8,
        'max_photos' => (int) env('HIGHLIGHT_MAX_PHOTOS', 30),
        // guests' short clips: at most this many, this many seconds from each
        'max_clips' => 3,
        'clip_seconds' => 4.0,
        'fps' => (int) env('HIGHLIGHT_FPS', 30),
        // the Ken Burns zoom at the end of each photo (1.0 = none)
        'zoom' => 1.12,
        // libx264 settings; "preset" trades speed for size
        'preset' => env('HIGHLIGHT_PRESET', 'veryfast'),
        'crf' => (int) env('HIGHLIGHT_CRF', 23),
        'audio_bitrate' => '160k',
        'formats' => [
            'vertical' => ['width' => 1080, 'height' => 1920],
            'landscape' => ['width' => 1920, 'height' => 1080],
        ],
        // the signed download link in the email works this many days
        'link_days' => 7,
    ],

    'photo_book' => [
        // trim sizes in millimetres
        'sizes' => [
            'a4_landscape' => ['width' => 297, 'height' => 210],
            'square_21' => ['width' => 210, 'height' => 210],
        ],
        'pages' => [20, 40],
        // print-shop margins: bleed past the trim edge, and the slug outside it that carries the crop marks
        'bleed_mm' => (float) env('PHOTO_BOOK_BLEED_MM', 3),
        'crop_marks' => (bool) env('PHOTO_BOOK_CROP_MARKS', true),
        'slug_mm' => (float) env('PHOTO_BOOK_SLUG_MM', 6),
        // images are resampled to this resolution at their printed size (never upscaled)
        'dpi' => (int) env('PHOTO_BOOK_DPI', 300),
        'jpeg_quality' => 90,
        'timeout' => (int) env('PHOTO_BOOK_TIMEOUT', 300),
    ],

];
