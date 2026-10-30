<?php

use App\Jobs\BuildHighlightVideo;
use App\Models\HighlightVideo;
use App\Models\MusicTrack;
use App\Notifications\HighlightVideoReady;
use App\Services\Album\Album;
use App\Services\Keepsakes\Ffmpeg;
use App\Services\Keepsakes\HighlightBuilder;
use App\Services\Keepsakes\HighlightPlan;
use App\Services\Keepsakes\HighlightVideos;
use App\Services\Keepsakes\TitleCard;
use App\Services\OgImage\Chromium;
use App\Services\Storage\StorageExpiry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Support\GuestExtras;
use Tests\Support\Journey;

/*
| The highlight video (plan section 14.5): a queued ffmpeg slideshow of the
| album's visible photos and clips, vertical and wide, stored under
| events/{id}/highlight/ (purged with the album), gated by its add-on.
| Tests that run ffmpeg or Chromium are skipped where they are missing.
*/

beforeEach(function () {
    [$this->event, $this->household] = GuestExtras::event('signature', ['starts_at' => now()->subDay()]);
    Notification::fake();
    // small and quick, at the real output sizes
    config([
        'keepsakes.video.fps' => 10,
        'keepsakes.video.preset' => 'ultrafast',
        'keepsakes.video.title_seconds' => 1.2,
        'keepsakes.video.fade_seconds' => 0.3,
        'keepsakes.video.photo_seconds' => ['min' => 1.0, 'max' => 1.5],
        'keepsakes.video.clip_seconds' => 1.0,
        'keepsakes.video.target_seconds' => 8,
    ]);
});

it('plans 60 to 90 seconds, shorter with few photos, never longer than 90', function () {
    config(['keepsakes.video' => (require base_path('config/keepsakes.php'))['video']]);

    $many = HighlightPlan::make(30, 3);
    expect($many['total'])->toBeGreaterThanOrEqual(60)->toBeLessThanOrEqual(90)
        ->and($many['photos'])->toBe(30);

    $few = HighlightPlan::make(3, 0);
    expect($few['total'])->toBeLessThan(60)->and($few['photo'])->toBe(6.0);

    config(['keepsakes.video.max_seconds' => 40]);
    $capped = HighlightPlan::make(30, 0);
    expect($capped['total'])->toBeLessThanOrEqual(40)->and($capped['photos'])->toBeLessThan(30);
});

it('is gated by its add-on and needs photos', function () {
    $videos = app(HighlightVideos::class);
    $owner = $this->event->owner;

    expect(fn () => $videos->request($this->event, $owner, ['vertical'], null))->toThrow(ValidationException::class);

    GuestExtras::grant($this->event, 'highlight_video');
    config(['keepsakes.video.ffmpeg' => '/usr/bin/ffmpeg']);
    if (Journey::ffmpeg()) {
        expect(fn () => $videos->request($this->event->fresh(), $owner, ['vertical'], null))->toThrow(ValidationException::class, trans('keepsakes.video.errors.no_photos'));
    }

    config(['keepsakes.video.ffmpeg' => '/nonexistent/ffmpeg']);
    Journey::photos($this->event, 2);
    expect(app(Ffmpeg::class)->available())->toBeFalse()
        ->and(fn () => $videos->request($this->event->fresh(), $owner, ['vertical'], null))->toThrow(ValidationException::class, trans('keepsakes.video.errors.unavailable'));
});

it('builds vertical and wide MP4s of the right size and length, and emails the links', function () {
    if (! Journey::ffmpeg()) {
        $this->markTestSkipped('ffmpeg is not installed (FFMPEG_PATH).');
    }
    GuestExtras::grant($this->event, 'highlight_video');
    $photos = Journey::photos($this->event, 4, 1600, 1200);
    $photos[3]->update(['hidden' => true]); // never in the video

    // a test tone as the admin's track (the app ships no music)
    $disk = Storage::disk(config('filesystems.media_disk'));
    $tone = sys_get_temp_dir().'/daawa-tone-'.uniqid().'.m4a';
    (new Process(['/usr/bin/ffmpeg', '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=3', '-c:a', 'aac', $tone]))->mustRun();
    $disk->put('music/tone.m4a', file_get_contents($tone));
    @unlink($tone);
    $track = MusicTrack::query()->create(['title' => 'Tone', 'license' => 'test', 'storage_key' => 'music/tone.m4a']);

    $this->actingAs($this->event->owner)->post("/events/{$this->event->id}/highlight-video", ['formats' => ['vertical', 'landscape'], 'music_track_id' => $track->id])
        ->assertSessionHasNoErrors();

    $video = HighlightVideo::query()->where('event_id', $this->event->id)->firstOrFail();
    expect($video->status)->toBe('ready', (string) $video->error)
        ->and($video->photo_count)->toBe(3);

    $plan = HighlightPlan::make(3, 0);
    foreach (['vertical' => [1080, 1920], 'landscape' => [1920, 1080]] as $format => [$w, $h]) {
        $out = $video->outputs[$format];
        expect($out['width'])->toBe($w)->and($out['height'])->toBe($h)
            ->and($out['duration'])->toBeGreaterThan($plan['total'] - 0.6)->toBeLessThan($plan['total'] + 0.6)
            ->and($out['duration'])->toBeLessThanOrEqual(90)
            ->and($out['key'])->toStartWith("events/{$this->event->id}/highlight/{$format}-")->toEndWith('.mp4')
            ->and($disk->exists($out['key']))->toBeTrue()
            ->and($disk->exists($out['poster']))->toBeTrue();

        $local = sys_get_temp_dir().'/daawa-check-'.uniqid().'.mp4';
        file_put_contents($local, $disk->get($out['key']));
        $info = app(Ffmpeg::class)->probe($local);
        $probe = new Process(['/usr/bin/ffmpeg', '-hide_banner', '-i', $local]);
        $probe->run();
        @unlink($local);
        expect([$info['width'], $info['height']])->toBe([$w, $h])
            ->and($probe->getErrorOutput())->toContain('Audio: aac');
    }

    Notification::assertSentTo($this->event->owner, HighlightVideoReady::class, function (HighlightVideoReady $n) {
        $mail = $n->toMail($this->event->owner);

        return str_contains((string) $mail->actionUrl, '/highlight/') && str_contains((string) $mail->actionUrl, 'signature=');
    });

    // the signed links work; unsigned ones don't
    $url = app(HighlightVideos::class)->url($video, 'vertical');
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'video/mp4');
    $this->get("/highlight/{$video->id}/vertical")->assertForbidden();

    // the album page previews it
    $this->actingAs($this->event->owner)->get("/dashboard/events/{$this->event->id}/album")
        ->assertInertia(fn ($page) => $page->where('highlight.status', 'ready')->has('highlight.outputs.vertical.url'));
});

it('records a failed build instead of crashing the queue', function () {
    GuestExtras::grant($this->event, 'highlight_video');
    Journey::photos($this->event, 2);
    config(['keepsakes.video.ffmpeg' => '/bin/false']);
    $video = HighlightVideo::query()->create(['event_id' => $this->event->id, 'formats' => ['vertical'], 'status' => 'queued']);

    (new BuildHighlightVideo($video->id))->handle(app(HighlightVideos::class), app(HighlightBuilder::class), app(TitleCard::class), app(Album::class));

    expect($video->fresh())->status->toBe('failed')->error->not->toBeEmpty();
});

it('renders the title card with Arabic shaped by Chromium', function () {
    $chromium = new Chromium;
    if (! $chromium->available()) {
        $this->markTestSkipped('Chromium is not installed (OG_CHROME_PATH).');
    }
    config(['og.embed_fonts' => false, 'og.chrome_no_sandbox' => true]);
    app()->instance(Chromium::class, $chromium);
    $card = app(TitleCard::class);

    $html = $card->html($this->event, $this->event->invitation->renderConfig(), 1080, 1920);
    expect($html)->toContain('dir="rtl"')->toContain('ريم');

    $result = $card->png($this->event, 1080, 1920);
    $image = imagecreatefromstring($result['png']);
    expect($result['rendered'])->toBeTrue()
        ->and([imagesx($image), imagesy($image)])->toBe([1080, 1920]);

    // the middle band holds dark text on the light card: many distinct colours, not a flat fill
    $colors = [];
    for ($x = 100; $x < 980; $x += 7) {
        for ($y = 700; $y < 1200; $y += 7) {
            $colors[imagecolorat($image, $x, $y)] = true;
        }
    }
    expect(count($colors))->toBeGreaterThan(20);
});

it('deletes the highlight files with the album when the storage period ends', function () {
    $disk = Storage::disk(config('filesystems.media_disk'));
    $disk->put("events/{$this->event->id}/highlight/vertical-x.mp4", 'mp4');
    $disk->put("events/{$this->event->id}/highlight/vertical-x.jpg", 'jpg');
    Journey::photos($this->event, 1);
    $this->event->update(['storage_until' => now()->subDays(2)->toDateString()]);

    app(StorageExpiry::class)->run(CarbonImmutable::today());

    expect($disk->allFiles("events/{$this->event->id}"))->toBe([]);
});

it('queues the build', function () {
    Queue::fake();
    GuestExtras::grant($this->event, 'highlight_video');
    Journey::photos($this->event, 2);
    config(['keepsakes.video.ffmpeg' => '/usr/bin/ffmpeg']);
    if (! Journey::ffmpeg()) {
        $this->markTestSkipped('ffmpeg is not installed (FFMPEG_PATH).');
    }

    $video = app(HighlightVideos::class)->request($this->event, $this->event->owner, ['landscape'], null);

    Queue::assertPushed(BuildHighlightVideo::class, fn ($job) => $job->videoId === $video->id);
    expect($video->formats)->toBe(['landscape']);
});
