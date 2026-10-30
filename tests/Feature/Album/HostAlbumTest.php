<?php

use App\Events\MediaUploaded;
use App\Models\GuestbookEntry;
use App\Models\Media;
use App\Models\User;
use App\Notifications\AlbumExportReady;
use App\Services\Album\Album;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\GuestExtras;

function hostAlbumUpload($test, string $token, string $name = 'a.jpg'): Media
{
    $test->post("/i/{$token}/uploads", ['file' => UploadedFile::fake()->image($name, 900, 600)], ['Accept' => 'application/json'])->assertCreated();

    return Media::query()->latest()->first();
}

it('shows the album page to the host with every item and the links', function () {
    [$event, $household] = GuestExtras::event();
    hostAlbumUpload($this, $household->token);
    $hidden = hostAlbumUpload($this, $household->token, 'b.jpg');
    $hidden->update(['hidden' => true]);

    $this->actingAs($event->owner)
        ->get("/dashboard/events/{$event->id}/album")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard/Album')
            ->where('enabled', true)
            ->where('wall', true)
            ->has('items', 2)
            ->has('households', 1)
            ->where('links.wall', fn ($url) => str_contains($url, "/e/{$event->invitation->slug}/wall"))
            ->where('links.upload', fn ($url) => str_contains($url, '/album'))
        );
});

it('keeps other hosts out of the album and its actions', function () {
    [$event, $household] = GuestExtras::event();
    $media = hostAlbumUpload($this, $household->token);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get("/dashboard/events/{$event->id}/album")->assertForbidden();
    $this->actingAs($stranger)->patchJson("/media/{$media->id}", ['hidden' => true])->assertForbidden();
    $this->actingAs($stranger)->deleteJson("/media/{$media->id}")->assertForbidden();
    $this->actingAs($stranger)->postJson("/events/{$event->id}/album/export")->assertForbidden();
    expect($media->fresh()->hidden)->toBeFalse();
});

it('lets the host hide, approve and delete items', function () {
    Events::fake([MediaUploaded::class]);
    [$event, $household] = GuestExtras::event(album: ['moderate' => true]);
    $media = hostAlbumUpload($this, $household->token);
    expect($media->hidden)->toBeTrue();

    // approving puts it on the wall
    $this->actingAs($event->owner)->patchJson("/media/{$media->id}", ['hidden' => false])
        ->assertOk()->assertJson(['hidden' => false, 'approved' => true]);
    Events::assertDispatched(MediaUploaded::class);

    $this->actingAs($event->owner)->patchJson("/media/{$media->id}", ['hidden' => true])->assertOk()->assertJson(['hidden' => true]);
    $this->getJson("/i/{$household->token}/album/items")->assertJsonCount(0, 'items');

    $disk = Storage::disk(config('filesystems.media_disk'));
    $this->actingAs($event->owner)->deleteJson("/media/{$media->id}")->assertOk();
    expect(Media::query()->count())->toBe(0)
        ->and($disk->exists($media->storage_key))->toBeFalse();
});

it('does not touch the host\'s own wizard uploads through the album routes', function () {
    [$event] = GuestExtras::event();
    $own = Media::factory()->create(['event_id' => $event->id, 'source' => 'host', 'kind' => 'photo', 'storage_key' => 'x.jpg']);

    $this->actingAs($event->owner)->patchJson("/media/{$own->id}", ['hidden' => true])->assertNotFound();
    $this->actingAs($event->owner)->deleteJson("/media/{$own->id}")->assertNotFound();
});

it('uses a guest photo as the cover or in the gallery, through the schema', function () {
    [$event, $household] = GuestExtras::event();
    $media = hostAlbumUpload($this, $household->token);

    $this->actingAs($event->owner)->postJson("/media/{$media->id}/use", ['as' => 'cover'])->assertOk();
    expect($event->invitation->fresh()->config['media']['cover'])->toEqual(['type' => 'image', 'src' => "media:{$media->id}"]);

    $this->actingAs($event->owner)->postJson("/media/{$media->id}/use", ['as' => 'gallery'])->assertOk();
    $config = $event->invitation->fresh()->config;
    $gallery = collect($config['sections'])->firstWhere('type', 'gallery');
    expect($gallery['photos'])->toBe([['src' => "media:{$media->id}"]])
        // placed before the RSVP
        ->and(collect($config['sections'])->search(fn ($s) => $s['type'] === 'gallery'))
        ->toBeLessThan(collect($config['sections'])->search(fn ($s) => $s['type'] === 'rsvp'));

    $this->actingAs($event->owner)->postJson("/media/{$media->id}/use", ['as' => 'gallery'])->assertOk()->assertJson(['message' => __('album.host.already_in_gallery')]);
});

it('saves the album settings and the PIN', function () {
    [$event] = GuestExtras::event();

    $this->actingAs($event->owner)->putJson("/dashboard/events/{$event->id}/album/settings", ['moderate' => true, 'upload_before_event' => true, 'pin' => '1234'])
        ->assertOk()
        ->assertJsonPath('settings.moderate', true)
        ->assertJsonPath('settings.has_pin', true);

    $album = app(Album::class);
    expect($album->verifyPin($event->fresh(), '1234'))->toBeTrue()
        ->and($event->fresh()->album['pin_hash'])->not->toBe('1234');

    $this->actingAs($event->owner)->putJson("/dashboard/events/{$event->id}/album/settings", ['pin' => '12'])->assertUnprocessable();
    $this->actingAs($event->owner)->putJson("/dashboard/events/{$event->id}/album/settings", ['pin' => null])->assertJsonPath('settings.has_pin', false);
});

it('exports the album as a ZIP with every file and emails a signed link', function () {
    Notification::fake();
    [$event, $household] = GuestExtras::event();
    $first = hostAlbumUpload($this, $household->token, 'one.jpg');
    hostAlbumUpload($this, $household->token, 'two.jpg')->update(['hidden' => true]);

    $this->actingAs($event->owner)->postJson("/events/{$event->id}/album/export")->assertAccepted();

    $export = $event->fresh()->album['export'];
    expect($export['status'])->toBe('ready')->and($export['count'])->toBe(2);

    $disk = Storage::disk(config('filesystems.media_disk'));
    [$zipKey] = $disk->files("events/{$event->id}/exports");
    $tmp = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($tmp, $disk->get($zipKey));
    $zip = new ZipArchive;
    $zip->open($tmp);
    expect($zip->numFiles)->toBe(2)
        ->and($zip->getNameIndex(0))->toEndWith('.jpg')
        ->and($zip->getFromIndex(0))->toBe($disk->get($first->storage_key));
    $zip->close();
    unlink($tmp);

    Notification::assertSentTo($event->owner, AlbumExportReady::class, fn ($n) => $n->count === 2 && str_contains($n->url, 'signature='));

    // the emailed link downloads it; without the signature it does not
    $this->get($export['url'])->assertOk()->assertDownload();
    $this->get(strtok($export['url'], '?'))->assertForbidden();
});

it('lists the guestbook for the host and deletes entries', function () {
    [$event, $household] = GuestExtras::event();
    $this->postJson("/i/{$household->token}/guestbook", ['text' => 'ألف مبروك!'])->assertCreated();
    $entry = GuestbookEntry::query()->sole();

    $this->actingAs($event->owner)->get("/dashboard/events/{$event->id}/album")
        ->assertInertia(fn (AssertableInertia $page) => $page->has('guestbook', 1)->where('guestbook.0.text', 'ألف مبروك!')->where('guestbook.0.name', 'عائلة خالد العلي'));

    $this->actingAs($event->owner)->deleteJson("/guestbook/{$entry->id}")->assertOk();
    expect(GuestbookEntry::query()->count())->toBe(0);
});

it('shows a friendly lock when the plan has no album', function () {
    [$event] = GuestExtras::event('premium');

    $this->actingAs($event->owner)->get("/dashboard/events/{$event->id}/album")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('enabled', false)->where('links', null));
    $this->actingAs($event->owner)->postJson("/events/{$event->id}/album/export")->assertForbidden();
});
