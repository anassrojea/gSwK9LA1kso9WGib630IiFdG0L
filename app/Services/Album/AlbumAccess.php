<?php

namespace App\Services\Album;

use App\Http\Middleware\SetLocale;
use App\Models\Event;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\OpenLink;

/**
 * Who is at /i/{token}/album: a household's personal link, the event's open
 * link, or the album's own link (events.album_token, the wall's QR). Like the
 * invitation, anything but a published invitation on a live event is a 404.
 */
final class AlbumAccess
{
    private function __construct(
        public readonly Event $event,
        public readonly Invitation $invitation,
        public readonly array $config,
        public readonly string $token,
        public readonly ?Household $household,
        /** a link shared by many people (open link, album link): uploads are limited per device */
        public readonly bool $shared,
    ) {}

    public static function resolve(string $token): self
    {
        $household = Household::query()->with('event.invitation')->where('token', $token)->first();
        $event = $household?->event;
        $shared = false;

        if (! $household) {
            $shared = true;
            $event = OpenLink::query()->with('event.invitation')->where('token', $token)->where('enabled', true)->first()?->event
                ?? (strlen($token) === Album::TOKEN_LENGTH ? Event::query()->with('invitation')->where('album_token', $token)->first() : null);
        }

        $invitation = $event?->invitation;
        abort_if(! $event || ! $invitation?->published_at || $event->isArchived(), 404);

        $config = $invitation->renderConfig();
        SetLocale::apply($config['locale']);

        return new self($event, $invitation, $config, $token, $household, $shared);
    }

    /** Rate-limit key: the household, or the token plus the device on a shared link. */
    public static function limiterKey(string $token, ?string $ip): string
    {
        return $token.'|'.(strlen($token) === Household::TOKEN_LENGTH ? '' : (string) $ip);
    }
}
