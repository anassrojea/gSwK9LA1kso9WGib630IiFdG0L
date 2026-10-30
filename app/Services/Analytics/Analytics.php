<?php

namespace App\Services\Analytics;

use App\Jobs\SendAnalyticsEvent;

/**
 * Server-side product analytics (plan section 22) through PostHog's capture
 * API. Queued, so a slow or failing PostHog never slows a request or a
 * webhook; a no-op without POSTHOG_KEY. Never send personal data here: ids,
 * amounts and plan names only.
 */
class Analytics
{
    public function enabled(): bool
    {
        return filled(config('services.posthog.key'));
    }

    /**
     * @param  string  $distinctId  the user's id, or "anonymous"
     * @param  array<string, mixed>  $properties
     */
    public function capture(string $event, string $distinctId, array $properties = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        SendAnalyticsEvent::dispatch($event, $distinctId, $properties, now()->toIso8601String());
    }

    /**
     * An event with no person behind it (e.g. the "صمّم دعوتك" click on a guest page):
     * no profile is created and nothing identifies the visitor.
     *
     * @param  array<string, mixed>  $properties
     */
    public function captureAnonymous(string $event, array $properties = []): void
    {
        $this->capture($event, 'anonymous', $properties + ['$process_person_profile' => false]);
    }

    public function endpoint(): string
    {
        return rtrim((string) config('services.posthog.host'), '/').'/i/v0/e/';
    }
}
