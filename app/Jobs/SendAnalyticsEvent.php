<?php

namespace App\Jobs;

use App\Services\Analytics\Analytics;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Posts one event to PostHog's capture API (App\Services\Analytics\Analytics).
 */
class SendAnalyticsEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    /**
     * @param  array<string, mixed>  $properties
     */
    public function __construct(
        public string $event,
        public string $distinctId,
        public array $properties,
        public string $timestamp,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(Analytics $analytics): void
    {
        if (! $analytics->enabled()) {
            return;
        }

        Http::acceptJson()
            ->timeout(10)
            ->post($analytics->endpoint(), [
                'api_key' => config('services.posthog.key'),
                'event' => $this->event,
                'distinct_id' => $this->distinctId,
                'properties' => $this->properties + ['$lib' => 'daawa-server'],
                'timestamp' => $this->timestamp,
            ])
            ->throw();
    }
}
