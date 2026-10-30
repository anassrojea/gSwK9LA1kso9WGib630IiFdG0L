<?php

namespace App\Http\Controllers\Invite;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Models\Invitation;
use App\Models\Media;
use App\Services\Album\Album;
use App\Services\Album\QrSvg;
use App\Services\Digits;
use App\Services\InvitationConfig\InvitationConfig;
use App\Services\InvitationConfig\ThemeStyles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The live photo wall for the hall screen (plan sections 11 and 14.1):
 *
 *   GET /e/{slug}/wall        (wall.show) full-screen slideshow + upload QR
 *   GET /e/{slug}/wall/feed   the latest visible photos (JSON)
 *
 * New photos arrive over Reverb on public-wall.{slug} (MediaUploaded) with a
 * polling fallback. Needs the photo_wall feature; when the host makes the
 * wall private, ?k={secret} is required.
 */
class WallController extends Controller
{
    public const FEED = 60;

    public function __construct(private Album $album) {}

    public function show(Request $request, string $slug): Response
    {
        [$invitation, $config] = $this->resolve($slug);
        $event = $invitation->event;
        $locale = $config['locale'];
        $view = [
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'themeVars' => ThemeStyles::cssVariables($config['theme']),
            'fontsUrl' => ThemeStyles::fontsUrl($config['theme']),
            'couple' => static::couple($config, $event->title),
        ];

        if (! $this->album->wallEnabled($event)) {
            return response()->view('album.wall-closed', $view + ['message' => __('album.wall.not_enabled')], 403);
        }
        if (! $this->album->wallKeyValid($event, $request->query('k'))) {
            return response()->view('album.wall-closed', $view + ['message' => __('album.wall.private')], 403);
        }

        $uploadUrl = $this->album->uploadUrl($event);

        return response()->view('album.wall', $view + [
            'qr' => QrSvg::make($uploadUrl),
            'uploadUrl' => $uploadUrl,
            'boot' => [
                'slug' => $slug,
                'channel' => 'public-wall.'.$slug,
                'feed' => route('wall.feed', $slug, false).($request->query('k') ? '?k='.urlencode((string) $request->query('k')) : ''),
                'poll' => (int) config('album.wall_poll'),
                'locale' => $locale,
                'reverb' => [
                    'key' => config('broadcasting.connections.reverb.key'),
                    'host' => config('broadcasting.connections.reverb.options.host'),
                    'port' => (int) config('broadcasting.connections.reverb.options.port'),
                    'scheme' => config('broadcasting.connections.reverb.options.scheme'),
                ],
            ],
        ]);
    }

    public function feed(Request $request, string $slug): JsonResponse
    {
        [$invitation] = $this->resolve($slug);
        $event = $invitation->event;
        abort_unless($this->album->wallEnabled($event) && $this->album->wallKeyValid($event, $request->query('k')), 403);

        $media = Media::query()
            ->album()
            ->visible()
            ->where('kind', 'photo')
            ->with('household')
            ->where('event_id', $event->getKey())
            ->latest()
            ->limit(self::FEED)
            ->get();

        $ttl = (int) config('album.url_ttl.wall');

        return response()->json([
            'items' => $media->map(fn (Media $m) => [
                'id' => $m->getKey(),
                'src' => $this->album->url($m, 'preview', $ttl),
                'thumb' => $this->album->url($m, 'thumb', $ttl),
                'name' => $m->uploader_name ?? $m->household?->display_name,
                'width' => $m->width,
                'height' => $m->height,
                'at' => $m->created_at?->toIso8601String(),
            ])->values(),
            'count' => $count = Media::query()->album()->visible()->where('event_id', $event->getKey())->count(),
            'count_label' => trans_choice('album.wall.count', $count, ['count' => Digits::localize($count, app()->getLocale())]),
        ]);
    }

    /**
     * @return array{0: Invitation, 1: array}
     */
    private function resolve(string $slug): array
    {
        $invitation = Invitation::query()->with('event.openLink')->where('slug', $slug)->first();
        abort_if(! $invitation?->published_at || $invitation->event->isArchived(), 404);

        $config = $invitation->renderConfig();
        SetLocale::apply($config['locale']);

        return [$invitation, $config];
    }

    public static function couple(array $config, ?string $fallback): string
    {
        $names = InvitationConfig::section($config, 'names');
        $joiner = ($config['locale'] ?? 'ar') === 'ar' ? ' و' : ' & ';

        return $names ? trim($names['first'].(isset($names['second']) ? $joiner.$names['second'] : '')) : (string) $fallback;
    }
}
