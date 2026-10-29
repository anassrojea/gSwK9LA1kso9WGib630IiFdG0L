<?php

namespace App\Http\Controllers\Invite;

use App\Http\Middleware\SetLocale;
use App\Models\Household;
use App\Models\Invitation;

trait ResolvesHousehold
{
    /**
     * The household for a personal link, with a published invitation on a
     * live event. Anything else is a plain 404 (no hint whether a token exists).
     *
     * @return array{0: Household, 1: Invitation, 2: array}
     */
    protected function resolve(string $token): array
    {
        $household = Household::query()
            ->with(['event.invitation', 'rsvp'])
            ->where('token', $token)
            ->first();

        $invitation = $household?->event?->invitation;

        abort_if(! $household || ! $invitation?->published_at || $household->event->isArchived(), 404);

        $config = $invitation->renderConfig();
        SetLocale::apply($config['locale']);

        return [$household, $invitation, $config];
    }
}
