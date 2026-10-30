<?php

namespace App\Http\Controllers\Invite;

use App\Http\Controllers\Controller;
use App\Models\GuestbookEntry;
use App\Models\Media;
use App\Services\Album\Album;
use App\Services\Album\AlbumAccess;
use App\Services\Album\AlbumUploads;
use App\Services\Digits;
use App\Services\InvitationConfig\InvitationConfig;
use App\Services\InvitationConfig\ThemeStyles;
use App\Services\Wizard\GuestListParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The guests' side of the event album (plan section 11), no account needed:
 *
 *   GET  /i/{token}/album          the album page: upload, browse, leave a message
 *   GET  /i/{token}/album/items    the photos guests may see (JSON)
 *   POST /i/{token}/album/pin      the host's PIN → a key the page keeps
 *   POST /i/{token}/uploads        a file (local disk) or a signed upload URL (S3)
 *   POST /i/{token}/uploads/complete  after a direct upload to S3
 *   POST /i/{token}/guestbook      a short text message for the hosts
 *   GET  /m/{media}/{variant}      a file, behind a signed expiring URL
 *
 * {token} is a household's link, the open link or the album's own link
 * (AlbumAccess). The PIN key travels in the X-Album-Key header.
 */
class AlbumController extends Controller
{
    public const PAGE = 60;

    public function __construct(private Album $album, private AlbumUploads $uploads) {}

    public function show(Request $request, string $token): Response
    {
        $access = AlbumAccess::resolve($token);
        $event = $access->event;
        $config = $access->config;
        $locale = $config['locale'];

        $names = InvitationConfig::section($config, 'names');
        $joiner = $locale === 'ar' ? ' و' : ' & ';
        $couple = $names ? trim($names['first'].(isset($names['second']) ? $joiner.$names['second'] : '')) : $event->title;

        $enabled = $this->album->enabled($event);
        $open = $this->album->uploadsOpen($event);
        $guestbookVideo = $enabled && $this->album->videoGuestbook($event);

        return response()->view('album.show', [
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'config' => $config,
            'couple' => $couple,
            'themeVars' => ThemeStyles::cssVariables($config['theme']),
            'fontsUrl' => ThemeStyles::fontsUrl($config['theme']),
            'enabled' => $enabled,
            'open' => $open,
            'shared' => $access->shared,
            'guestName' => $access->household?->display_name,
            'inviteUrl' => $access->household || $access->shared && strlen($token) !== Album::TOKEN_LENGTH ? route('invite.show', $token, false) : null,
            'boot' => [
                'locale' => $locale,
                'enabled' => $enabled,
                'open' => $open,
                'pin' => $enabled && $this->album->hasPin($event),
                'direct' => $this->album->directUploads(),
                'maxEdge' => (int) config('album.max_edge'),
                'photoMaxMb' => (int) config('album.photo_max_mb'),
                'video' => config('album.video'),
                'guestbookVideo' => $guestbookVideo ? config('album.guestbook_video') : null,
                'urls' => [
                    'items' => route('album.items', $token, false),
                    'pin' => route('album.pin', $token, false),
                    'upload' => route('album.upload', $token, false),
                    'complete' => route('album.complete', $token, false),
                    'guestbook' => route('album.guestbook', $token, false),
                ],
                'strings' => trans('album.guest'),
            ],
        ]);
    }

    public function items(Request $request, string $token): JsonResponse
    {
        $access = AlbumAccess::resolve($token);
        $this->authorizeGuest($request, $access, needsOpen: false);

        $before = $request->query('before');
        $media = Media::query()
            ->album()
            ->visible()
            ->with('household')
            ->where('event_id', $access->event->getKey())
            ->when(is_string($before) && strtotime($before), fn ($q) => $q->where('created_at', '<', $before))
            ->latest()
            ->limit(self::PAGE + 1)
            ->get();

        $more = $media->count() > self::PAGE;
        $media = $media->take(self::PAGE);

        return response()->json([
            'items' => $media->map(fn (Media $m) => $this->album->item($m))->values(),
            'next' => $more ? $media->last()?->created_at?->toIso8601String() : null,
        ]);
    }

    public function pin(Request $request, string $token): JsonResponse
    {
        $access = AlbumAccess::resolve($token);
        abort_unless($this->album->enabled($access->event), 404);

        $pin = Digits::toWestern((string) $request->input('pin'));
        if (! $this->album->verifyPin($access->event, $pin)) {
            return response()->json(['message' => __('album.guest.pin_wrong'), 'errors' => ['pin' => [__('album.guest.pin_wrong')]]], 422);
        }

        return response()->json(['key' => $this->album->pinKey($access->event)]);
    }

    /**
     * A file from the device (multipart, local disk), or — on S3 — {mime, bytes}
     * for a signed upload URL the browser PUTs the file to.
     */
    public function upload(Request $request, string $token): JsonResponse
    {
        $access = AlbumAccess::resolve($token);
        $this->authorizeGuest($request, $access);

        $meta = $request->validate([
            'duration' => ['nullable', 'numeric', 'min:0', 'max:36000'],
            'name' => ['nullable', 'string', 'max:'.GuestListParser::MAX_NAME],
            'guestbook' => ['nullable', 'boolean'],
        ]);

        if (! $request->hasFile('file') && $this->album->directUploads()) {
            $data = $request->validate([
                'mime' => ['required', 'string', 'max:64'],
                'bytes' => ['required', 'integer', 'min:1'],
            ]);

            return response()->json(['direct' => $this->uploads->signDirect($access, $data['mime'], (int) $data['bytes'], $meta)]);
        }

        $request->validate([
            'file' => ['required', 'file'],
            'poster' => ['nullable', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,image/webp'],
        ]);

        $media = $this->uploads->storeFile($access, $request->file('file'), $meta, $request->file('poster'));

        return response()->json($this->uploaded($media), 201);
    }

    public function complete(Request $request, string $token): JsonResponse
    {
        $access = AlbumAccess::resolve($token);
        $this->authorizeGuest($request, $access);
        abort_unless($this->album->directUploads(), 404);

        $request->validate(['upload' => ['required', 'string', 'max:4000']]);
        try {
            $ticket = json_decode(Crypt::decryptString($request->string('upload')->toString()), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(422);
        }

        $media = $this->uploads->completeDirect($access, $ticket);

        return response()->json($this->uploaded($media), 201);
    }

    public function guestbook(Request $request, string $token): JsonResponse
    {
        $access = AlbumAccess::resolve($token);
        $this->authorizeGuest($request, $access, needsOpen: false);

        $data = $request->validate([
            'text' => ['required', 'string', 'max:500'],
            'name' => [$access->household ? 'nullable' : 'required', 'string', 'max:'.GuestListParser::MAX_NAME],
        ]);

        $entry = GuestbookEntry::query()->create([
            'event_id' => $access->event->getKey(),
            'household_id' => $access->household?->getKey(),
            'kind' => 'text',
            'text' => trim(($access->household ? '' : trim($data['name']).': ').trim($data['text'])),
        ]);

        return response()->json(['id' => $entry->getKey(), 'message' => __('album.guest.guestbook_thanks')], 201);
    }

    /**
     * GET /m/{media}/{variant}: one file behind a signed, expiring URL (Album::url).
     * Guests' URLs stop working as soon as the host hides the item.
     */
    public function file(Request $request, Media $media, string $variant): SymfonyResponse
    {
        abort_if($media->hidden && ! $request->boolean('all'), 404);

        $key = $this->album->key($media, $variant);
        $disk = $this->album->disk();
        abort_if(! $key || ! $disk->exists($key), 404);

        return $disk->response($key, null, [
            'Cache-Control' => 'public, max-age=300',
            'Content-Disposition' => 'inline',
        ]);
    }

    private function authorizeGuest(Request $request, AlbumAccess $access, bool $needsOpen = true): void
    {
        if (! $this->album->enabled($access->event)) {
            abort(response()->json(['message' => __('album.guest.not_enabled'), 'reason' => 'not_enabled'], 403));
        }
        if (! $this->album->keyValid($access->event, $request->header('X-Album-Key') ?? $request->input('key'))) {
            abort(response()->json(['message' => __('album.guest.pin_needed'), 'reason' => 'pin'], 403));
        }
        if ($needsOpen && ! $this->album->uploadsOpen($access->event)) {
            abort(response()->json(['message' => __('album.guest.closed'), 'reason' => 'closed'], 403));
        }
    }

    private function uploaded(Media $media): array
    {
        return [
            'item' => $this->album->item($media),
            'pending' => $media->hidden,
            'message' => $media->hidden ? __('album.guest.pending') : __('album.guest.uploaded'),
        ];
    }
}
