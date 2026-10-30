<?php

use App\Events\MediaUploaded;
use App\Models\GuestbookEntry;
use App\Models\Media;
use App\Models\OpenLink;
use App\Services\Album\Album;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Tests\Support\GuestExtras;

it('names the wall route wall.show at /e/{slug}/wall', function () {
    expect(Route::has('wall.show'))->toBeTrue()
        ->and(route('wall.show', 'abc-wedding', false))->toBe('/e/abc-wedding/wall');
});

it('shows the wall with an upload QR on Signature', function () {
    [$event] = GuestExtras::event();
    $slug = $event->invitation->slug;

    $this->get("/e/{$slug}/wall")
        ->assertOk()
        ->assertSee('<svg', false)
        ->assertSee('wall-boot', false)
        ->assertSee("public-wall.{$slug}", false)
        ->assertSee(__('album.wall.scan'));
});

it('points the wall QR at the open link when it is on', function () {
    [$event] = GuestExtras::event();
    $link = OpenLink::query()->create(['event_id' => $event->id, 'enabled' => true, 'cap' => 50]);

    $this->get("/e/{$event->invitation->slug}/wall")->assertSee("/i/{$link->token}/album");
});

it('locks the wall without the photo_wall feature', function () {
    [$event] = GuestExtras::event('premium');
    GuestExtras::grant($event, 'photo_cloud');

    $this->get("/e/{$event->invitation->slug}/wall")->assertForbidden()->assertSee(__('album.wall.not_enabled'));
    $this->getJson("/e/{$event->invitation->slug}/wall/feed")->assertForbidden();
});

it('needs the secret link for a private wall', function () {
    [$event] = GuestExtras::event(album: ['wall_private' => true]);
    $secret = app(Album::class)->wallSecret($event);
    $slug = $event->invitation->slug;

    $this->get("/e/{$slug}/wall")->assertForbidden();
    $this->get("/e/{$slug}/wall?k=wrong")->assertForbidden();
    $this->get("/e/{$slug}/wall?k={$secret}")->assertOk();
    $this->getJson("/e/{$slug}/wall/feed?k={$secret}")->assertOk();
});

it('feeds only approved, visible photos to the wall', function () {
    [$event, $household] = GuestExtras::event(album: ['moderate' => true]);
    $this->post("/i/{$household->token}/uploads", ['file' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json']);
    $slug = $event->invitation->slug;

    $this->getJson("/e/{$slug}/wall/feed")->assertOk()->assertJsonCount(0, 'items');

    $media = Media::query()->sole();
    $this->actingAs($event->owner)->patchJson("/media/{$media->id}", ['hidden' => false]);
    $feed = $this->getJson("/e/{$slug}/wall/feed")->assertOk()->assertJsonCount(1, 'items')->json('items.0');
    expect($feed['id'])->toBe($media->id)->and($feed['src'])->toContain('signature=')->and($feed['name'])->toBe('عائلة خالد العلي');
});

it('broadcasts new photos on public-wall.{slug} without URLs', function () {
    [$event] = GuestExtras::event();
    $media = Media::factory()->create(['event_id' => $event->id, 'source' => 'guest', 'kind' => 'photo', 'storage_key' => 'x.jpg']);
    $broadcast = new MediaUploaded($media);

    expect($broadcast)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($broadcast->broadcastOn()->name)->toBe('public-wall.'.$event->invitation->slug)
        ->and($broadcast->broadcastAs())->toBe('media.uploaded')
        ->and(array_keys($broadcast->broadcastWith()))->toBe(['id', 'kind', 'at'])
        ->and($broadcast->broadcastWhen())->toBeTrue();

    $media->hidden = true;
    expect((new MediaUploaded($media))->broadcastWhen())->toBeFalse();
});

it('takes a text message for the guestbook', function () {
    [$event, $household] = GuestExtras::event();

    $this->postJson("/i/{$household->token}/guestbook", ['text' => '  ألف مبروك  '])->assertCreated();
    $entry = GuestbookEntry::query()->sole();
    expect($entry)->kind->toBe('text')->text->toBe('ألف مبروك')->household_id->toBe($household->id);

    $this->postJson("/i/{$household->token}/guestbook", ['text' => str_repeat('a', 501)])->assertUnprocessable();
});

it('asks for a name on a shared link guestbook', function () {
    [$event] = GuestExtras::event();
    $token = app(Album::class)->albumToken($event);

    $this->postJson("/i/{$token}/guestbook", ['text' => 'Congrats'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson("/i/{$token}/guestbook", ['text' => 'Congrats', 'name' => 'Sara'])->assertCreated();
    expect(GuestbookEntry::query()->sole()->text)->toBe('Sara: Congrats');
});

it('allows a 30 second video message only with the video guestbook add-on', function () {
    [$event, $household] = GuestExtras::event();
    $video = fn (int $seconds) => ['file' => UploadedFile::fake()->create('wish.mp4', 500, 'video/mp4'), 'duration' => $seconds, 'guestbook' => 1];

    $this->post("/i/{$household->token}/uploads", $video(10), ['Accept' => 'application/json'])->assertUnprocessable();

    GuestExtras::grant($event, 'video_guestbook');
    $this->post("/i/{$household->token}/uploads", $video(45), ['Accept' => 'application/json'])->assertUnprocessable();
    $this->post("/i/{$household->token}/uploads", $video(20), ['Accept' => 'application/json'])->assertCreated();

    $entry = GuestbookEntry::query()->sole();
    expect($entry->kind)->toBe('video')
        ->and($entry->media->source)->toBe('guestbook')
        ->and($entry->media->hidden)->toBeTrue();
    // not part of the album or the wall
    $this->getJson("/i/{$household->token}/album/items")->assertJsonCount(0, 'items');
});
