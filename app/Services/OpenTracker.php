<?php

namespace App\Services;

use App\Events\HouseholdOpened;
use App\Models\Household;
use App\Models\LinkOpen;
use App\Models\OpenLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Open tracking for personal links (plan section 8): first / last open and a
 * count per household, plus a row in link_opens. Only the token's household
 * and a hash of the user agent are stored. Reloads from the same device
 * within the dedupe window count once, so the page view and the /state call
 * it triggers never double count.
 *
 * Each recorded open is broadcast to the host dashboard (HouseholdOpened).
 * The broadcast is queued, and a broken queue or socket server never turns
 * into an error for the guest.
 */
class OpenTracker
{
    public const DEDUPE_SECONDS = 600;

    /**
     * Link-preview fetchers (WhatsApp, Telegram, …) read the page to build the
     * preview card when a link is shared; that is not the guest opening it.
     */
    public const CRAWLERS = ['WhatsApp/', 'facebookexternalhit', 'TelegramBot', 'Twitterbot', 'Slackbot', 'Discordbot'];

    public static function isCrawler(?string $userAgent): bool
    {
        if (! $userAgent) {
            return false;
        }

        foreach (self::CRAWLERS as $needle) {
            if (stripos($userAgent, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    public function record(Household $household, ?string $userAgent): bool
    {
        if (static::isCrawler($userAgent)) {
            return false;
        }

        $uaHash = $userAgent ? hash('sha256', $userAgent) : null;

        if (! Cache::add('invite-open:'.$household->getKey().':'.substr($uaHash ?? '-', 0, 16), 1, self::DEDUPE_SECONDS)) {
            return false;
        }

        $now = now();

        DB::transaction(function () use ($household, $uaHash, $now) {
            LinkOpen::query()->create(['household_id' => $household->getKey(), 'opened_at' => $now, 'ua_hash' => $uaHash]);

            Household::query()->whereKey($household->getKey())->update([
                'open_count' => DB::raw('open_count + 1'),
                'last_opened_at' => $now,
            ]);
            Household::query()->whereKey($household->getKey())->whereNull('first_opened_at')->update([
                'first_opened_at' => $now,
            ]);
        });

        rescue(fn () => HouseholdOpened::dispatch($household));

        return true;
    }

    /**
     * Opens of an event's open link are counted on the link itself (first,
     * last, count); there is no household until someone replies.
     */
    public function recordOpenLink(OpenLink $link, ?string $userAgent): bool
    {
        if (static::isCrawler($userAgent)) {
            return false;
        }

        $uaHash = $userAgent ? hash('sha256', $userAgent) : '-';

        if (! Cache::add('invite-open-link:'.$link->getKey().':'.substr($uaHash, 0, 16), 1, self::DEDUPE_SECONDS)) {
            return false;
        }

        $now = now();

        DB::transaction(function () use ($link, $now) {
            OpenLink::query()->whereKey($link->getKey())->update([
                'open_count' => DB::raw('open_count + 1'),
                'last_opened_at' => $now,
            ]);
            OpenLink::query()->whereKey($link->getKey())->whereNull('first_opened_at')->update([
                'first_opened_at' => $now,
            ]);
        });

        return true;
    }
}
