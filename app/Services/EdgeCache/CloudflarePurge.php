<?php

namespace App\Services\EdgeCache;

use App\Models\Event;
use App\Models\Household;
use App\Models\OpenLink;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * Purges guest invitation URLs from Cloudflare's edge cache (plan section 6.4).
 * Invitations are cached for up to s-maxage (300 s); a purge makes an edit,
 * a rename or an unpublish visible at once. Purges by URL, 30 per API call
 * (Cloudflare's limit for "files"), on both domains (APP_URL and INVITE_URL).
 * Needs CLOUDFLARE_ZONE_ID and CLOUDFLARE_API_TOKEN (Zone → Cache Purge).
 */
class CloudflarePurge
{
    /** Cloudflare accepts at most 30 URLs per purge request on every plan. */
    public const BATCH = 30;

    public const ENDPOINT = 'https://api.cloudflare.com/client/v4/zones/%s/purge_cache';

    public function configured(): bool
    {
        return filled(config('services.cloudflare.zone_id')) && filled(config('services.cloudflare.api_token'));
    }

    /**
     * Every cached URL of an event: each personal link and its preview image,
     * the open link, and /e/{slug} when a page is served there.
     *
     * @return list<string>
     */
    public function urlsForEvent(Event $event): array
    {
        $tokens = $event->households()
            ->whereRaw('not (? = any(tags))', [OpenLink::TAG])
            ->orderBy('id')
            ->pluck('token')
            ->all();

        if ($openToken = $event->openLink()->value('token')) {
            $tokens[] = $openToken;
        }

        $urls = $this->urlsForTokens($tokens);

        $slug = $event->invitation()->value('slug');
        if ($slug && $this->servesEventPage()) {
            foreach ($this->bases() as $base) {
                $urls[] = "{$base}/e/{$slug}";
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param  iterable<string>  $tokens
     * @return list<string>
     */
    public function urlsForTokens(iterable $tokens): array
    {
        $urls = [];
        foreach ($tokens as $token) {
            if (! is_string($token) || ! preg_match('/^[A-Za-z0-9]{10,32}$/', $token)) {
                continue;
            }
            foreach ($this->bases() as $base) {
                $urls[] = "{$base}/i/{$token}";
                $urls[] = "{$base}/i/{$token}/og.png";
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * The household's URLs (rename, delete).
     *
     * @return list<string>
     */
    public function urlsForHousehold(Household $household): array
    {
        return $this->urlsForTokens([$household->token]);
    }

    /**
     * Sends the purge requests, 30 URLs each. Throws on an API error so the
     * queued job retries with backoff.
     *
     * @param  list<string>  $urls
     * @return int the number of API calls made
     *
     * @throws RequestException
     */
    public function purge(array $urls): int
    {
        $urls = array_values(array_unique($urls));
        if ($urls === [] || ! $this->configured()) {
            return 0;
        }

        $endpoint = sprintf(self::ENDPOINT, config('services.cloudflare.zone_id'));
        $calls = 0;

        foreach (array_chunk($urls, self::BATCH) as $batch) {
            $response = Http::withToken((string) config('services.cloudflare.api_token'))
                ->acceptJson()
                ->timeout(15)
                ->post($endpoint, ['files' => $batch]);
            $calls++;

            if ($response->failed() || $response->json('success') === false) {
                throw new RequestException($response);
            }
        }

        return $calls;
    }

    /**
     * The origins guests reach invitations on: the app domain and the short link domain.
     *
     * @return list<string>
     */
    public function bases(): array
    {
        return array_values(array_unique(array_filter([
            rtrim((string) config('app.url'), '/'),
            rtrim((string) config('app.invite_url'), '/'),
        ])));
    }

    private function servesEventPage(): bool
    {
        foreach (Route::getRoutes()->get('GET') as $route) {
            if ($route->uri() === 'e/{slug}') {
                return true;
            }
        }

        return false;
    }
}
