<?php

namespace App\Observers;

use App\Jobs\PurgeEdgeCache;
use App\Models\Event;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\OpenLink;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * Queues PurgeEdgeCache when something a cached guest page shows changes
 * (plan section 6.4): an invitation is published, unpublished or edited after
 * publishing; a household is renamed or deleted; the open link is switched,
 * re-tokened or deleted; the event's plan (badge, features) or status changes
 * (ended, archived: the page answers 404); an uploaded file is deleted.
 * Drafts are never cached, so nothing is purged before the first publish.
 * Registered in AppServiceProvider.
 */
class EdgeCachePurger implements ShouldHandleEventsAfterCommit
{
    public function updated(Model $model): void
    {
        match (true) {
            $model instanceof Invitation => $this->invitation($model),
            $model instanceof Household => $model->wasChanged(['display_name', 'token'])
                ? $this->tokens($model->event_id, [$model->token, $model->getPrevious()['token'] ?? null])
                : null,
            $model instanceof OpenLink => $model->wasChanged(['enabled', 'cap', 'token'])
                ? $this->tokens($model->event_id, [$model->token, $model->getPrevious()['token'] ?? null])
                : null,
            $model instanceof Event => $model->wasChanged(['plan_id', 'status']) && $this->published($model->getKey())
                ? PurgeEdgeCache::forEvent($model)
                : null,
            $model instanceof Media => $model->event_id && $model->wasChanged(['hidden', 'storage_key']) && $this->published($model->event_id)
                ? PurgeEdgeCache::forEvent($model->event_id)
                : null,
            default => null,
        };
    }

    public function deleted(Model $model): void
    {
        if ($model instanceof Household || $model instanceof OpenLink) {
            $this->tokens($model->event_id, [$model->token]);
        } elseif ($model instanceof Invitation && $model->published_at) {
            PurgeEdgeCache::forEvent($model->event_id);
        } elseif ($model instanceof Media && $model->event_id && $this->published($model->event_id)) {
            // a photo or cover removed (by the host, or by events:expire-storage after the storage period)
            PurgeEdgeCache::forEvent($model->event_id);
        }
    }

    private function invitation(Invitation $invitation): void
    {
        $publishedOrUnpublished = $invitation->wasChanged('published_at');
        $editedLive = $invitation->published_at && $invitation->wasChanged(['config', 'template_id', 'slug']);

        if ($publishedOrUnpublished || $editedLive) {
            PurgeEdgeCache::forEvent($invitation->event_id);
        }
    }

    /**
     * @param  list<?string>  $tokens
     */
    private function tokens(string $eventId, array $tokens): void
    {
        if ($this->published($eventId)) {
            PurgeEdgeCache::forTokens($eventId, array_values(array_filter($tokens)));
        }
    }

    private function published(string $eventId): bool
    {
        return Invitation::query()->where('event_id', $eventId)->whereNotNull('published_at')->exists();
    }
}
