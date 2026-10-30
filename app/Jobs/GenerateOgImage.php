<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\Household;
use App\Models\OpenLink;
use App\Services\OgImage\OgImage;
use App\Services\PublishGate;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Renders one link preview image ahead of time (household created or renamed,
 * invitation published or edited, open link switched on), so WhatsApp's
 * crawler gets it from the cache. A household id of null means the event's
 * open link. The job reads the current name and config when it runs, so
 * duplicates queued by a burst of edits are dropped (unique until processing).
 */
class GenerateOgImage implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    // under Horizon's 60 s worker timeout (config/horizon.php); one render is capped by OG_TIMEOUT (30 s)
    public int $timeout = 55;

    public int $uniqueFor = 600;

    public function __construct(
        public string $eventId,
        public ?string $householdId = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->eventId.':'.($this->householdId ?? 'open');
    }

    public function handle(OgImage $og): void
    {
        if (! $og->available()) {
            Log::warning('OG image skipped: Chromium not found (set OG_CHROME_PATH).');

            return;
        }

        $event = Event::query()->with(['invitation', 'openLink'])->find($this->eventId);
        $invitation = $event?->invitation;
        if (! $invitation?->published_at || $event->isArchived()) {
            return;
        }

        if ($this->householdId === null) {
            if ($event->openLink?->enabled) {
                $og->ensure($invitation);
            }

            return;
        }

        $household = Household::query()->where('event_id', $event->getKey())->find($this->householdId);
        if ($household) {
            $og->ensure($invitation, $household);
        }
    }

    /**
     * Queue every card of an event: each personal link and the open link.
     * Households created by open-link replies have no card of their own.
     */
    public static function warmEvent(Event $event, int $delaySeconds = 0): void
    {
        // Free events share the generic card (OgImage::subject): one render is enough
        if (! app(PublishGate::class)->canShowPersonalPreview($event)) {
            if ($first = $event->households()->whereRaw('not (? = any(tags))', [OpenLink::TAG])->orderBy('id')->value('id')) {
                static::dispatch($event->getKey(), $first)->delay($delaySeconds);
            } elseif ($event->openLink()->where('enabled', true)->exists()) {
                static::dispatch($event->getKey())->delay($delaySeconds);
            }

            return;
        }

        $event->households()
            ->whereRaw('not (? = any(tags))', [OpenLink::TAG])
            ->select('id')
            ->orderBy('id')
            ->chunk(500, function ($households) use ($event, $delaySeconds) {
                foreach ($households as $household) {
                    static::dispatch($event->getKey(), $household->getKey())->delay($delaySeconds);
                }
            });

        if ($event->openLink()->where('enabled', true)->exists()) {
            static::dispatch($event->getKey())->delay($delaySeconds);
        }
    }
}
