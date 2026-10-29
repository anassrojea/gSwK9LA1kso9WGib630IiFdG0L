<?php

namespace App\Http\Controllers\Invite;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\OpenLink;
use App\Services\OgImage\OgImage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class OgImageController extends Controller
{
    /**
     * GET /i/{token}/og.png: the link preview image for a personal link or the
     * open link. Served from the media disk; a missing image is rendered here
     * within OG_SYNC_TIMEOUT, else the guest gets the generic fallback.
     * Never counted as an open (WhatsApp's crawler fetches it on every share).
     *
     * Caching: the page's og:image URL carries ?v={hash}; when it matches the
     * current image the response is immutable for a year at the edge,
     * anything else (an outdated or missing v) is cached for five minutes.
     */
    public function show(Request $request, string $token, OgImage $og): Response|RedirectResponse
    {
        [$invitation, $household] = $this->resolve($token);

        $config = $invitation->renderConfig();
        $hash = $og->hash($invitation, $config, $household);
        $path = $og->path($invitation, $household, $hash);
        $disk = $og->disk();

        if (! $disk->exists($path)) {
            try {
                $timeout = (int) config('og.sync_timeout');
                // one render per image even when many crawlers ask at once
                $path = Cache::lock('og-render:'.$path, $timeout + 30)
                    ->block($timeout, fn () => $og->ensure($invitation, $household, $timeout));
            } catch (LockTimeoutException) {
                return $this->fallback();
            } catch (Throwable $e) {
                Log::warning('OG image could not be rendered.', ['path' => $path, 'error' => $e->getMessage()]);

                return $this->fallback();
            }
        }

        $current = hash_equals($hash, (string) $request->query('v', ''));

        return response($disk->get($path), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => $current
                ? 'public, max-age=86400, s-maxage=31536000, immutable'
                : 'public, max-age=300, s-maxage=300',
        ]);
    }

    private function fallback(): RedirectResponse
    {
        return redirect()
            ->to(rtrim((string) config('app.invite_url'), '/').'/'.config('og.fallback'))
            ->header('Cache-Control', 'public, max-age=60, s-maxage=60');
    }

    /**
     * @return array{0: Invitation, 1: ?Household} the household is null for the open link
     */
    private function resolve(string $token): array
    {
        $household = Household::query()->with('event.invitation')->where('token', $token)->first();
        $event = $household?->event;

        if (! $household) {
            $event = OpenLink::query()->with('event.invitation')->where('token', $token)->where('enabled', true)->first()?->event;
        }

        $invitation = $event?->invitation;
        abort_if(! $invitation?->published_at || $event->isArchived(), 404);

        return [$invitation, $household];
    }
}
