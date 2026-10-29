<?php

namespace App\Observers;

use App\Jobs\GenerateOgImage;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\OpenLink;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * Queues link preview images (GenerateOgImage) when what they show changes:
 * a household is created or renamed, an invitation is published or edited,
 * an open link is switched on. Only for published invitations; drafts get
 * theirs on publish. Registered in AppServiceProvider; off with OG_WARM=false.
 */
class OgImageWarmer implements ShouldHandleEventsAfterCommit
{
    public function created(Model $model): void
    {
        if ($model instanceof Household) {
            $this->household($model);
        } elseif ($model instanceof OpenLink) {
            $this->openLink($model);
        }
    }

    public function updated(Model $model): void
    {
        if ($model instanceof Household && $model->wasChanged('display_name')) {
            $this->household($model);
        } elseif ($model instanceof OpenLink && $model->wasChanged('enabled')) {
            $this->openLink($model);
        } elseif ($model instanceof Invitation && $model->published_at && $model->wasChanged(['published_at', 'config'])) {
            if (! config('og.warm') || ! $model->event) {
                return;
            }
            // publishing renders right away; edits wait so a burst of autosaves renders once
            GenerateOgImage::warmEvent($model->event, $model->wasChanged('published_at') ? 0 : (int) config('og.edit_delay'));
        }
    }

    private function household(Household $household): void
    {
        if (! config('og.warm') || in_array(OpenLink::TAG, $household->tags ?? [], true)) {
            return;
        }

        if ($household->event()->whereHas('invitation', fn ($q) => $q->whereNotNull('published_at'))->exists()) {
            GenerateOgImage::dispatch($household->event_id, $household->getKey());
        }
    }

    private function openLink(OpenLink $link): void
    {
        if (! config('og.warm') || ! $link->enabled) {
            return;
        }

        if ($link->event()->whereHas('invitation', fn ($q) => $q->whereNotNull('published_at'))->exists()) {
            GenerateOgImage::dispatch($link->event_id);
        }
    }
}
