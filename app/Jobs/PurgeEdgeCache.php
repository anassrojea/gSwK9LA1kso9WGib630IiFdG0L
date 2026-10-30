<?php

namespace App\Jobs;

use App\Models\Event;
use App\Services\EdgeCache\CloudflarePurge;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Purges an event's guest pages from Cloudflare (plan section 6.4), queued by
 * App\Observers\EdgeCachePurger. Without $tokens it purges every URL of the
 * event, read when the job runs; with $tokens only those links (a household
 * renamed or deleted, an open link that changed token).
 *
 * Debounced: it waits CLOUDFLARE_PURGE_DELAY seconds and is unique per event
 * (and token set) until it starts, so a burst of autosaves purges once, and an
 * edit made while it runs queues the next one. No-op when Cloudflare is not
 * configured. Retries with backoff on API errors.
 */
class PurgeEdgeCache implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 50;

    /** The lock is released when the job starts; this only bounds a lost one. */
    public int $uniqueFor = 60;

    /**
     * @param  list<string>|null  $tokens
     */
    public function __construct(
        public string $eventId,
        public ?array $tokens = null,
    ) {}

    public static function forEvent(Event|string $event): void
    {
        $id = $event instanceof Event ? $event->getKey() : $event;

        static::dispatch($id)->delay(static::delaySeconds());
    }

    /**
     * @param  list<string>  $tokens
     */
    public static function forTokens(Event|string $event, array $tokens): void
    {
        $tokens = array_values(array_unique(array_filter($tokens)));
        if ($tokens === []) {
            return;
        }
        sort($tokens);

        $id = $event instanceof Event ? $event->getKey() : $event;

        static::dispatch($id, $tokens)->delay(static::delaySeconds());
    }

    public function uniqueId(): string
    {
        return $this->eventId.($this->tokens ? ':'.md5(implode(',', $this->tokens)) : '');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 90, 300];
    }

    public function handle(CloudflarePurge $cloudflare): void
    {
        if (! $cloudflare->configured()) {
            Log::info('Edge cache purge skipped: CLOUDFLARE_ZONE_ID / CLOUDFLARE_API_TOKEN are not set.', ['event' => $this->eventId]);

            return;
        }

        if ($this->tokens !== null) {
            $urls = $cloudflare->urlsForTokens($this->tokens);
        } else {
            $event = Event::query()->find($this->eventId);
            if (! $event) {
                return;
            }
            $urls = $cloudflare->urlsForEvent($event);
        }

        $calls = $cloudflare->purge($urls);

        Log::info('Edge cache purged.', ['event' => $this->eventId, 'urls' => count($urls), 'calls' => $calls]);
    }

    private static function delaySeconds(): int
    {
        return max(0, (int) config('services.cloudflare.purge_delay', 10));
    }
}
