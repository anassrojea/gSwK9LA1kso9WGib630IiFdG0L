<?php

/*
| The event album and live photo wall (plan section 11). Files live on
| config('filesystems.media_disk') under events/{event_id}/album/...; the
| storage period itself (events.storage_until) is handled by the storage-expiry job.
| See App\Services\Album\Album.
*/

return [

    // Total album size per event in MB, by plan; "photo_cloud" applies to any plan with the photo cloud add-on.
    'max_mb' => [
        'default' => (int) env('ALBUM_MAX_MB', 5120),
        'signature' => (int) env('ALBUM_MAX_MB_SIGNATURE', 10240),
        'venue_pro' => (int) env('ALBUM_MAX_MB_VENUE_PRO', 10240),
        'photo_cloud' => (int) env('ALBUM_MAX_MB_PHOTO_CLOUD', 10240),
    ],

    // One upload is checked again on the server: photos arrive compressed (max 2560 px).
    'photo_max_mb' => (int) env('ALBUM_PHOTO_MAX_MB', 15),
    'max_edge' => 2560,

    // Videos: the browser checks the duration, the server the size.
    'video' => [
        'max_seconds' => (int) env('ALBUM_VIDEO_MAX_SECONDS', 60),
        'max_mb' => (int) env('ALBUM_VIDEO_MAX_MB', 100),
    ],

    // Video guestbook add-on (entitlement "video_guestbook")
    'guestbook_video' => [
        'max_seconds' => 30,
        'max_mb' => 50,
    ],

    // Per household (or per device on a shared link), per hour
    'uploads_per_hour' => (int) env('ALBUM_UPLOADS_PER_HOUR', 60),
    // A shared link (open link, the album's own link) as a whole, per hour
    'shared_uploads_per_hour' => (int) env('ALBUM_SHARED_UPLOADS_PER_HOUR', 600),
    'guestbook_per_hour' => 10,

    // Thumbnails (WebP, made with GD by App\Jobs\MakeThumbnails)
    'thumbs' => ['thumb' => 400, 'preview' => 1200],
    'thumb_quality' => 80,

    // Signed URL lifetimes, in minutes
    'url_ttl' => [
        'original' => 60,
        'preview' => 360,
        // the hall screen runs all night
        'wall' => 720,
    ],

    // ZIP exports: the emailed link works this many days
    'export_days' => 7,

    // How often the wall asks for new photos when Reverb is not connected (seconds)
    'wall_poll' => 15,

];
