<?php

namespace App\Http\Controllers\Wizard;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Media;
use App\Services\Digits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * POST /events/{event}/media: the host's own uploads (cover, gallery, music).
 * The config refers to them as media:<uuid>, which only resolves for this
 * event (App\Services\InvitationConfig\MediaUrl). Images arrive already
 * compressed to WebP by the browser; the limits here are the hard stop.
 */
class MediaController extends Controller
{
    /** purpose => allowed mime types => [kind, extension] */
    private const TYPES = [
        'image' => [
            'image/jpeg' => ['photo', 'jpg'],
            'image/png' => ['photo', 'png'],
            'image/webp' => ['photo', 'webp'],
        ],
        'video' => [
            'video/mp4' => ['video', 'mp4'],
        ],
        'audio' => [
            'audio/mpeg' => ['audio', 'mp3'],
            'audio/mp3' => ['audio', 'mp3'],
            'audio/mp4' => ['audio', 'm4a'],
            'audio/x-m4a' => ['audio', 'm4a'],
            'audio/m4a' => ['audio', 'm4a'],
            // an .m4a is an MP4 container; some systems report it as video/mp4
            'video/mp4' => ['audio', 'm4a'],
        ],
    ];

    /** kilobytes */
    public const MAX_KB = ['photo' => 10 * 1024, 'video' => 50 * 1024, 'audio' => 10 * 1024];

    public function store(Request $request, Event $event): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'in:image,video,audio'],
            'file' => ['required', 'file', 'max:'.max(self::MAX_KB)],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $type = $request->string('type')->toString();
        $mime = (string) $file->getMimeType();

        [$kind, $extension] = self::TYPES[$type][$mime]
            ?? throw ValidationException::withMessages(['file' => trans("app.wizard.media.bad_type.{$type}")]);

        if ($type === 'audio' && $mime === 'video/mp4' && strtolower($file->getClientOriginalExtension()) !== 'm4a') {
            throw ValidationException::withMessages(['file' => trans('app.wizard.media.bad_type.audio')]);
        }

        if ($file->getSize() > self::MAX_KB[$kind] * 1024) {
            throw ValidationException::withMessages(['file' => trans('app.wizard.media.too_large', ['max' => Digits::localize(self::MAX_KB[$kind] / 1024)])]);
        }

        $id = (string) Str::uuid();
        $disk = Storage::disk(config('filesystems.media_disk', 'public'));
        $key = $disk->putFileAs("events/{$event->getKey()}", $file, "{$id}.{$extension}", ['visibility' => 'public']);
        abort_unless($key, 500);

        $size = $kind === 'photo' ? @getimagesize($file->getRealPath()) : false;

        $media = new Media([
            'event_id' => $event->getKey(),
            'kind' => $kind,
            'storage_key' => $key,
            'width' => $size ? $size[0] : null,
            'height' => $size ? $size[1] : null,
            'bytes' => $file->getSize(),
        ]);
        $media->id = $id;
        $media->save();

        return response()->json([
            'id' => $id,
            'ref' => "media:{$id}",
            'url' => $disk->url($key),
            'kind' => $kind,
            'width' => $media->width,
            'height' => $media->height,
        ], 201);
    }
}
