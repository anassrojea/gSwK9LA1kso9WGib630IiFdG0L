<?php

use App\Models\Event;
use App\Models\Media;
use App\Models\User;
use App\Services\InvitationConfig\MediaUrl;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed([PlanSeeder::class, CatalogSeeder::class]);
    Storage::fake('public');
});

function upload(Event $event, string $type, UploadedFile $file)
{
    return test()->postJson("/events/{$event->id}/media", ['type' => $type, 'file' => $file]);
}

it('stores a photo for the guest\'s own draft and returns a media reference', function () {
    $event = wizardDraft();

    $response = upload($event, 'image', UploadedFile::fake()->image('cover.png', 1200, 800))
        ->assertCreated()
        ->assertJsonStructure(['id', 'ref', 'url', 'kind']);

    $media = Media::query()->findOrFail($response->json('id'));
    expect($response->json('ref'))->toBe('media:'.$media->id)
        ->and($media->event_id)->toBe($event->id)
        ->and($media->kind)->toBe('photo')
        ->and($media->width)->toBe(1200)
        ->and($media->storage_key)->toStartWith("events/{$event->id}/");
    Storage::disk('public')->assertExists($media->storage_key);

    // the reference resolves for this event only
    expect((new MediaUrl($event))('media:'.$media->id))->toBe($response->json('url'))
        ->and((new MediaUrl(Event::factory()->create()))('media:'.$media->id))->toBeNull();
});

it('accepts mp4 videos and mp3 music', function () {
    $event = wizardDraft();

    upload($event, 'video', UploadedFile::fake()->create('cover.mp4', 2048, 'video/mp4'))->assertCreated()->assertJsonPath('kind', 'video');
    upload($event, 'audio', UploadedFile::fake()->create('song.mp3', 1024, 'audio/mpeg'))->assertCreated()->assertJsonPath('kind', 'audio');

    expect(Media::query()->where('event_id', $event->id)->pluck('kind')->sort()->values()->all())->toBe(['audio', 'video']);
});

it('rejects the wrong kind of file', function (string $type, UploadedFile $file) {
    $event = wizardDraft();

    upload($event, $type, $file)->assertUnprocessable()->assertJsonValidationErrors('file');

    expect(Media::query()->count())->toBe(0);
})->with([
    'svg as a photo' => fn () => ['image', UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml')],
    'pdf as a photo' => fn () => ['image', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')],
    'photo as a video' => fn () => ['video', UploadedFile::fake()->image('x.png')],
    'webm video' => fn () => ['video', UploadedFile::fake()->create('x.webm', 10, 'video/webm')],
    'video as music' => fn () => ['audio', UploadedFile::fake()->create('x.mp4', 10, 'video/mp4')],
]);

it('enforces the size limits', function () {
    $event = wizardDraft();

    upload($event, 'image', UploadedFile::fake()->create('big.jpg', 10 * 1024 + 1, 'image/jpeg'))->assertJsonValidationErrors('file');
    upload($event, 'audio', UploadedFile::fake()->create('big.mp3', 10 * 1024 + 1, 'audio/mpeg'))->assertJsonValidationErrors('file');
    upload($event, 'video', UploadedFile::fake()->create('big.mp4', 50 * 1024 + 1, 'video/mp4'))->assertJsonValidationErrors('file');
    upload($event, 'video', UploadedFile::fake()->create('ok.mp4', 50 * 1024, 'video/mp4'))->assertCreated();
});

it('does not let anyone else upload to an event', function () {
    $event = wizardDraft();
    $this->flushSession();

    upload($event, 'image', UploadedFile::fake()->image('x.png'))->assertUnauthorized();

    $this->actingAs(User::factory()->create());
    upload($event, 'image', UploadedFile::fake()->image('x.png'))->assertForbidden();

    $owned = Event::factory()->create();
    upload($owned, 'image', UploadedFile::fake()->image('x.png'))->assertForbidden();

    $this->actingAs($owned->owner);
    upload($owned, 'image', UploadedFile::fake()->image('x.png'))->assertCreated();
    expect(Media::query()->where('event_id', $event->id)->count())->toBe(0);
});
