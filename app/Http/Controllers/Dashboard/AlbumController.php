<?php

namespace App\Http\Controllers\Dashboard;

use App\Events\MediaUploaded;
use App\Http\Controllers\Controller;
use App\Jobs\ExportAlbumZip;
use App\Models\Event;
use App\Models\GuestbookEntry;
use App\Models\Media;
use App\Services\Album\Album;
use App\Services\Album\QrSvg;
use App\Services\Digits;
use App\Services\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The host's album page (plan section 11): every guest upload with lazy
 * thumbnails, filter by family, hide / approve / delete, use a photo as the
 * cover or in the gallery section, the guestbook, the wall and its settings,
 * and "download all" as a queued ZIP.
 *
 *   GET    /dashboard/events/{event}/album
 *   PUT    /dashboard/events/{event}/album/settings
 *   PATCH  /media/{media}                 {hidden}
 *   DELETE /media/{media}
 *   POST   /media/{media}/use             {as: cover|gallery}
 *   DELETE /guestbook/{entry}
 *   POST   /events/{event}/album/export
 *   GET    /album-exports/{event}/{file}  (signed, from the email)
 */
class AlbumController extends Controller
{
    public const MAX_ITEMS = 1000;

    public function __construct(private Album $album) {}

    public function show(Request $request, Event $event): Response
    {
        Gate::authorize('view', $event);
        $event->loadMissing(['invitation', 'openLink']);

        $media = Media::query()
            ->album()
            ->with('household:id,display_name')
            ->where('event_id', $event->getKey())
            ->latest()
            ->limit(self::MAX_ITEMS)
            ->get();

        $guestbook = GuestbookEntry::query()
            ->with(['household:id,display_name', 'media'])
            ->where('event_id', $event->getKey())
            ->latest()
            ->limit(500)
            ->get();

        $settings = $this->album->settings($event);
        $enabled = $this->album->enabled($event);
        $published = (bool) $event->invitation?->published_at;

        return Inertia::render('dashboard/Album', [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'timezone' => $event->timezone ?: config('app.timezone'),
                'storage_until' => $event->storage_until?->toDateString(),
            ],
            'enabled' => $enabled,
            'wall' => $this->album->wallEnabled($event),
            'can' => ['update' => $request->user()->can('update', $event)],
            'settings' => [
                'upload_before_event' => $settings['upload_before_event'],
                'moderate' => $settings['moderate'],
                'has_pin' => $settings['pin_hash'] !== null,
                'wall_private' => $settings['wall_private'],
            ],
            'open' => $this->album->uploadsOpen($event),
            'usage' => ['used' => $this->album->usedBytes($event), 'max' => $this->album->maxBytes($event)],
            'items' => $media->map(fn (Media $m) => [
                ...$this->album->item($m, all: true),
                'hidden' => $m->hidden,
                'approved' => $m->approved_at !== null,
                'household_id' => $m->household_id,
                'bytes' => $m->bytes,
            ])->values(),
            'households' => $media->pluck('household')->filter()->unique('id')->map(fn ($h) => ['id' => $h->id, 'name' => $h->display_name])->values(),
            'guestbook' => $guestbook->map(fn (GuestbookEntry $g) => [
                'id' => $g->id,
                'kind' => $g->kind,
                'text' => $g->text,
                'name' => $g->household?->display_name,
                'video' => $g->media ? $this->album->url($g->media, 'original', all: true) : null,
                'at' => $g->created_at?->toIso8601String(),
            ])->values(),
            'export' => $settings['export'] ? [
                'status' => $settings['export']['status'],
                'at' => $settings['export']['at'] ?? null,
                'url' => ($settings['export']['status'] ?? null) === 'ready' ? ($settings['export']['url'] ?? null) : null,
                'count' => $settings['export']['count'] ?? null,
            ] : null,
            'links' => $enabled && $published ? [
                'upload' => $upload = $this->album->uploadUrl($event),
                'upload_qr' => QrSvg::make($upload),
                'wall' => $this->album->wallEnabled($event) ? $this->album->wallUrl($event) : null,
            ] : null,
            'urls' => [
                'settings' => route('dashboard.events.album.settings', $event, false),
                'export' => route('events.album.export', $event, false),
                'dashboard' => route('dashboard.events.show', $event, false),
                'upgrade' => route('dashboard.events.upgrade', $event, false),
            ],
            'plan' => $event->plan_id ?? Entitlements::FALLBACK_PLAN,
        ]);
    }

    public function settings(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'upload_before_event' => ['sometimes', 'boolean'],
            'moderate' => ['sometimes', 'boolean'],
            'wall_private' => ['sometimes', 'boolean'],
            'pin' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9٠-٩]{4,8}$/u'],
        ], ['pin.regex' => __('album.host.pin_rule')]);

        $changes = array_intersect_key($data, array_flip(['upload_before_event', 'moderate', 'wall_private']));
        if ($changes) {
            $this->album->update($event, array_map('boolval', $changes));
        }
        if (array_key_exists('pin', $data)) {
            $this->album->setPin($event, $data['pin'] === null ? null : Digits::toWestern($data['pin']));
        }
        if ($changes['wall_private'] ?? false) {
            $this->album->wallSecret($event);
        }

        $settings = $this->album->settings($event);

        return response()->json([
            'settings' => [
                'upload_before_event' => $settings['upload_before_event'],
                'moderate' => $settings['moderate'],
                'has_pin' => $settings['pin_hash'] !== null,
                'wall_private' => $settings['wall_private'],
            ],
            'open' => $this->album->uploadsOpen($event),
            'wall' => $this->album->wallEnabled($event) ? $this->album->wallUrl($event->load('invitation')) : null,
        ]);
    }

    /**
     * PATCH /media/{media} {hidden}: hide an item, or show / approve it.
     */
    public function update(Request $request, Media $media): JsonResponse
    {
        Gate::authorize('update', $media->event);
        abort_unless($media->source === Media::SOURCE_GUEST, 404);

        $data = $request->validate(['hidden' => ['required', 'boolean']]);
        $wasHidden = $media->hidden;

        $media->hidden = (bool) $data['hidden'];
        if (! $media->hidden) {
            $media->approved_at ??= now();
        }
        $media->save();

        // approved for the wall: the hall screen gets it now
        if ($wasHidden && ! $media->hidden) {
            MediaUploaded::dispatch($media);
        }

        return response()->json(['id' => $media->id, 'hidden' => $media->hidden, 'approved' => $media->approved_at !== null]);
    }

    public function destroy(Media $media): JsonResponse
    {
        Gate::authorize('update', $media->event);
        abort_unless(in_array($media->source, [Media::SOURCE_GUEST, Media::SOURCE_GUESTBOOK], true), 404);

        $this->deleteFiles($media);
        $media->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * POST /media/{media}/use {as}: a guest's photo as the invitation's cover, or
     * one more photo in its gallery section (created when missing). The config
     * is validated against the schema like any wizard edit.
     */
    public function use(Request $request, Media $media): JsonResponse
    {
        $event = $media->event;
        Gate::authorize('update', $event);
        abort_unless($media->source === Media::SOURCE_GUEST && $media->kind === 'photo', 404);
        $data = $request->validate(['as' => ['required', Rule::in(['cover', 'gallery'])]]);

        $invitation = $event->invitation;
        abort_unless($invitation, 404);
        $config = $invitation->config;
        $ref = 'media:'.$media->id;

        if ($data['as'] === 'cover') {
            $config['media'] = array_merge($config['media'] ?? [], ['cover' => ['type' => 'image', 'src' => $ref]]);
        } else {
            $index = collect($config['sections'])->search(fn ($s) => $s['type'] === 'gallery');
            if ($index === false) {
                $at = collect($config['sections'])->search(fn ($s) => in_array($s['type'], ['rsvp', 'album_teaser', 'closing'], true));
                array_splice($config['sections'], $at === false ? count($config['sections']) : $at, 0, [['type' => 'gallery', 'photos' => [['src' => $ref]]]]);
            } else {
                $photos = $config['sections'][$index]['photos'];
                if (in_array($ref, array_column($photos, 'src'), true)) {
                    return response()->json(['message' => __('album.host.already_in_gallery')]);
                }
                if (count($photos) >= 12) {
                    throw ValidationException::withMessages(['as' => __('album.host.gallery_full')]);
                }
                $config['sections'][$index]['photos'][] = ['src' => $ref];
            }
        }

        // a hidden photo would not show on the invitation (MediaUrl skips hidden uploads)
        DB::transaction(function () use ($invitation, $config, $media) {
            $invitation->config = $config;
            $invitation->save();
            if ($media->hidden) {
                $media->update(['hidden' => false, 'approved_at' => $media->approved_at ?? now()]);
            }
        });

        return response()->json(['message' => __($data['as'] === 'cover' ? 'album.host.cover_set' : 'album.host.added_to_gallery')]);
    }

    public function destroyGuestbook(GuestbookEntry $entry): JsonResponse
    {
        Gate::authorize('update', $entry->event);

        if ($entry->media) {
            $this->deleteFiles($entry->media);
            $entry->media->delete();
        }
        $entry->delete();

        return response()->json(['deleted' => true]);
    }

    public function export(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('view', $event);
        abort_unless($this->album->enabled($event), 403);

        $current = $this->album->settings($event)['export'];
        if (($current['status'] ?? null) === 'running' && now()->subMinutes(30)->lt($current['at'] ?? 0)) {
            return response()->json(['export' => $current]);
        }

        $export = ['status' => 'running', 'at' => now()->toIso8601String()];
        $this->album->update($event, ['export' => $export]);
        ExportAlbumZip::dispatch($event->getKey(), $request->user()->getKey());

        return response()->json(['export' => $export, 'message' => __('album.host.export_started')], 202);
    }

    /**
     * GET /album-exports/{event}/{file}: the ZIP, behind the signed link from the email.
     */
    public function download(Event $event, string $file): StreamedResponse
    {
        $key = "events/{$event->getKey()}/exports/{$file}";
        $disk = $this->album->disk();
        abort_unless($disk->exists($key), 404);

        return $disk->download($key, $file, ['Cache-Control' => 'no-store, private']);
    }

    private function deleteFiles(Media $media): void
    {
        $this->album->disk()->delete(array_values(array_filter([$media->storage_key, $media->thumb_key, $media->preview_key])));
    }
}
