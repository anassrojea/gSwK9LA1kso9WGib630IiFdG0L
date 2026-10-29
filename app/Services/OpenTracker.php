<?php

namespace App\Services;

use App\Models\Household;
use App\Models\LinkOpen;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Open tracking for personal links (plan section 8): first / last open and a
 * count per household, plus a row in link_opens. Only the token's household
 * and a hash of the user agent are stored. Reloads from the same device
 * within the dedupe window count once, so the page view and the /state call
 * it triggers never double count.
 */
class OpenTracker
{
    public const DEDUPE_SECONDS = 600;

    public function record(Household $household, ?string $userAgent): bool
    {
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

        return true;
    }
}
