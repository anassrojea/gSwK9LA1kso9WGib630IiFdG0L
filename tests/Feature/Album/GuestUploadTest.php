<?php

use App\Events\MediaUploaded;
use App\Jobs\MakeThumbnails;
use App\Models\Media;
use App\Models\OpenLink;
use App\Services\Album\Album;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GuestExtras;

function albumPhoto(int $w = 1600, int $h = 1200): UploadedFile
{
    return UploadedFile::fake()->image('party.jpg', $w, $h);
}

it('shows the album page for a household link and the open link', function () {
    [$event, $household] = GuestExtras::event();

    $this->get("/i/{$household->token}/album")
        ->assertOk()
        ->assertSee('album-boot', false)
        ->assertSee(__('album.guest.choose'))
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $link = OpenLink::query()->create(['event_id' => $event->id, 'enabled' => true, 'cap' => 50]);
    $this->get("/i/{$link->token}/album")->assertOk()->assertSee('data-name', false);
});

it('accepts a photo from a household and makes 400 and 1200 px WebP thumbnails', function () {
    Events::fake([MediaUploaded::class]);
    [$event, $household] = GuestExtras::event();

    $response = $this->post("/i/{$household->token}/uploads", ['file' => albumPhoto(3000, 2000)], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('pending', false);

    $media = Media::query()->sole();
    expect($media->source)->toBe('guest')
        ->and($media->household_id)->toBe($household->id)
        ->and($media->kind)->toBe('photo')
        ->and($media->hidden)->toBeFalse()
        ->and($media->storage_key)->toStartWith("events/{$event->id}/album/")
        ->and($media->thumb_key)->toEndWith('-400.'.Album::thumbExtension())
        ->and($media->preview_key)->toEndWith('-1200.'.Album::thumbExtension())
        ->and($response->json('item.thumb'))->toStartWith("/m/{$media->id}/thumb?");

    $disk = Storage::disk(config('filesystems.media_disk'));
    [$w, $h] = getimagesizefromstring($disk->get($media->thumb_key));
    expect(max($w, $h))->toBe(400);
    [$w] = getimagesizefromstring($disk->get($media->preview_key));
    expect($w)->toBe(1200);

    Events::assertDispatched(MediaUploaded::class, fn ($e) => $e->media->is($media) && $e->broadcastOn()->name === 'public-wall.'.$event->invitation->slug);
});

it('queues the thumbnail job after an upload', function () {
    Queue::fake();
    [, $household] = GuestExtras::event();

    $this->post("/i/{$household->token}/uploads", ['file' => albumPhoto()], ['Accept' => 'application/json'])->assertCreated();

    Queue::assertPushed(MakeThumbnails::class, fn ($job) => $job->mediaId === Media::query()->sole()->id);
});

it('returns 404 for an unknown token', function () {
    GuestExtras::event();

    $this->post('/i/AbCdEfGhIjKl/uploads', ['file' => albumPhoto()], ['Accept' => 'application/json'])->assertNotFound();
    $this->get('/i/AbCdEfGhIjKl/album')->assertNotFound();
});

it('refuses uploads when the plan has no album', function (string $plan) {
    [, $household] = GuestExtras::event($plan);

    $this->post("/i/{$household->token}/uploads", ['file' => albumPhoto()], ['Accept' => 'application/json'])
        ->assertForbidden()
        ->assertJsonPath('reason', 'not_enabled');
    $this->get("/i/{$household->token}/album")->assertOk()->assertSee(__('album.guest.not_enabled'));
    expect(Media::query()->count())->toBe(0);
})->with(['free', 'premium']);

it('opens the album with the photo cloud add-on on any plan', function () {
    [$event, $household] = GuestExtras::event('premium');
    GuestExtras::grant($event, 'photo_cloud');

    $this->post("/i/{$household->token}/uploads", ['file' => albumPhoto()], ['Accept' => 'application/json'])->assertCreated();
});

it('waits for the party unless uploads before the event are allowed', function () {
    [$event, $household] = GuestExtras::event(attributes: ['starts_at' => now()->addDays(3)]);

    $this->post("/i/{$household->token}/uploads", ['file' => albumPhoto()], ['Accept' => 'application/json'])
        ->assertForbidden()
        ->assertJsonPath('reason', 'closed');

    app(Album::class)->update($event, ['upload_before_event' => true]);
    $this->post("/i/{$household->token}/uploads", ['file' => albumPhoto()], ['Accept' => 'application/json'])->assertCreated();
});

it('needs the PIN key when the host set a PIN', function () {
    [$event, $household] = GuestExtras::event();
    $album = app(Album::class);
    $album->setPin($event, '2468');

    $this->post("/i/{$household->token}/uploads", ['file' => albumPhoto()], ['Accept' => 'application/json'])
        ->assertForbidden()->assertJsonPath('reason', 'pin');
    $this->getJson("/i/{$household->token}/album/items")->assertForbidden();

    $this->postJson("/i/{$household->token}/album/pin", ['pin' => '1111'])->assertUnprocessable();
    // Arabic-Indic digits are the same PIN
    $key = $this->postJson("/i/{$household->token}/album/pin", ['pin' => '٢٤٦٨'])->assertOk()->json('key');

    $this->post("/i/{$household->token}/uploads", ['file' => albumPhoto()], ['Accept' => 'application/json', 'X-Album-Key' => $key])->assertCreated();
    $this->getJson("/i/{$household->token}/album/items", ['X-Album-Key' => $key])->assertOk()->assertJsonCount(1, 'items');

    // a new PIN locks out the old key
    $album->setPin($event->fresh(), '9999');
    $this->getJson("/i/{$household->token}/album/items", ['X-Album-Key' => $key])->assertForbidden();
});

it('checks the type and size of each file', function () {
    [, $household] = GuestExtras::event();
    config()->set('album.video.max_mb', 1);

    $this->post("/i/{$household->token}/uploads", ['file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    // a "photo" that is not an image
    $this->post("/i/{$household->token}/uploads", ['file' => UploadedFile::fake()->create('fake.jpg', 10, 'image/jpeg')], ['Accept' => 'application/json'])
        ->assertUnprocessable();
    $this->post("/i/{$household->token}/uploads", ['file' => UploadedFile::fake()->create('big.mp4', 2048, 'video/mp4')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    // the browser reports the duration; too long is refused
    $this->post("/i/{$household->token}/uploads", ['file' => UploadedFile::fake()->create('long.mp4', 100, 'video/mp4'), 'duration' => 75], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    $this->post("/i/{$household->token}/uploads", ['file' => UploadedFile::fake()->create('clip.mp4', 100, 'video/mp4'), 'duration' => 12.4], ['Accept' => 'application/json'])
        ->assertCreated();
    expect(Media::query()->sole())->kind->toBe('video')->duration->toBe(12);
});

it('stops at the album size of the plan', function () {
    [$event, $household] = GuestExtras::event();
    config()->set('album.max_mb.signature', 1);
    Media::factory()->create(['event_id' => $event->id, 'source' => 'guest', 'kind' => 'photo', 'storage_key' => 'x.jpg', 'bytes' => 1024 * 1024 - 100]);

    $this->post("/i/{$household->token}/uploads", ['file' => albumPhoto()], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonPath('errors.file.0', __('album.guest.full'));
});

it('rate-limits uploads per household per hour', function () {
    [, $household] = GuestExtras::event();
    config()->set('album.uploads_per_hour', 2);

    $this->post("/i/{$household->token}/uploads", ['file' => albumPhoto(200, 200)], ['Accept' => 'application/json'])->assertCreated();
    $this->post("/i/{$household->token}/uploads", ['file' => albumPhoto(200, 200)], ['Accept' => 'application/json'])->assertCreated();
    $this->post("/i/{$household->token}/uploads", ['file' => albumPhoto(200, 200)], ['Accept' => 'application/json'])->assertTooManyRequests();
});

it('keeps uploads hidden until approved when moderation is on', function () {
    Events::fake([MediaUploaded::class]);
    [$event, $household] = GuestExtras::event(album: ['moderate' => true]);

    $this->post("/i/{$household->token}/uploads", ['file' => albumPhoto()], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('pending', true);

    expect(Media::query()->sole())->hidden->toBeTrue()->approved_at->toBeNull();
    $this->getJson("/i/{$household->token}/album/items")->assertJsonCount(0, 'items');
    Events::assertNotDispatched(MediaUploaded::class);
});

it('stores the guest name typed on a shared link', function () {
    [$event] = GuestExtras::event();
    $token = app(Album::class)->albumToken($event);

    $this->post("/i/{$token}/uploads", ['file' => albumPhoto(), 'name' => '  سارة  '], ['Accept' => 'application/json'])->assertCreated();

    expect(Media::query()->sole())->household_id->toBeNull()->uploader_name->toBe('سارة');
});

it('serves files behind signed URLs that stop working once hidden', function () {
    [$event, $household] = GuestExtras::event();
    $this->post("/i/{$household->token}/uploads", ['file' => albumPhoto()], ['Accept' => 'application/json']);
    $media = Media::query()->sole();
    $album = app(Album::class);

    $url = $album->url($media, 'original');
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->get($album->url($media, 'thumb'))->assertOk()->assertHeader('Content-Type', Album::thumbExtension() === 'webp' ? 'image/webp' : 'image/jpeg');

    // tampered or unsigned
    $this->get("/m/{$media->id}/original")->assertForbidden();
    $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();

    $media->update(['hidden' => true]);
    $this->get($url)->assertNotFound();
    $this->get($album->url($media, 'original', all: true))->assertOk();

    // expired
    $this->travel(2)->hours();
    $this->get($album->url($media->fresh(), 'original', 1, all: true))->assertOk();
    $expired = $url;
    $this->get($expired)->assertForbidden();
});

it('lists only visible guest photos for guests', function () {
    [$event, $household] = GuestExtras::event();
    Media::factory()->create(['event_id' => $event->id, 'source' => 'guest', 'kind' => 'photo', 'storage_key' => 'a.jpg', 'hidden' => false]);
    Media::factory()->create(['event_id' => $event->id, 'source' => 'guest', 'kind' => 'photo', 'storage_key' => 'b.jpg', 'hidden' => true]);
    // the host's own wizard uploads are not part of the album
    Media::factory()->create(['event_id' => $event->id, 'source' => 'host', 'kind' => 'photo', 'storage_key' => 'c.jpg']);

    $this->getJson("/i/{$household->token}/album/items")->assertOk()->assertJsonCount(1, 'items');
});

it('turns the invitation teaser into an upload link once the party started', function () {
    [$event, $household] = GuestExtras::event();

    $this->get("/i/{$household->token}")->assertOk()
        ->assertSee("/i/{$household->token}/album", false)
        ->assertSee('data-album-link', false);

    $event->update(['starts_at' => now()->addWeek()]);
    $this->get("/i/{$household->token}")->assertOk()->assertSee(__('invite.album.opens_at_party'));

    [, $free] = GuestExtras::event('free');
    $this->get("/i/{$free->token}")->assertOk()->assertDontSee('data-album-link', false)->assertSee(__('invite.album.soon'));
});
