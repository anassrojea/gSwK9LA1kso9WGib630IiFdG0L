<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\SiteCounter;
use App\Services\Analytics\Analytics;
use App\Services\OpenTracker;
use App\Support\SiteUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * GET /r/design-yours?e={invitation slug}: the "صمّم دعوتك" link at the end of
 * every guest invitation (plan sections 14.7 and 22). Counts the click per
 * event, anonymously (no cookie, IP or id is kept), then sends the visitor
 * to the landing page in the invitation's language with UTM parameters.
 */
class DesignYoursController extends Controller
{
    public function __invoke(Request $request, Analytics $analytics): RedirectResponse
    {
        $slug = (string) $request->query('e');
        $invitation = preg_match('/^[a-z0-9-]{3,80}$/', $slug)
            ? Invitation::query()->with('event:id,occasion')->where('slug', $slug)->first(['id', 'event_id', 'config'])
            : null;
        $locale = ($invitation?->config['locale'] ?? null) === 'en' ? 'en' : 'ar';

        if (! static::isBot($request->userAgent())) {
            SiteCounter::bump(SiteCounter::DESIGN_YOURS, $invitation?->event_id);
            $analytics->captureAnonymous('design_yours_clicked', [
                'occasion' => $invitation?->event?->occasion,
                'locale' => $locale,
            ]);
        }

        $query = http_build_query([
            'utm_source' => 'invite',
            'utm_medium' => 'footer',
            'utm_campaign' => 'design_yours',
        ]);

        return redirect()->away(SiteUrl::to('home', $locale).'?'.$query)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private static function isBot(?string $userAgent): bool
    {
        return $userAgent === null
            || Str::contains($userAgent, OpenTracker::CRAWLERS, ignoreCase: true)
            || Str::contains($userAgent, ['bot', 'crawler', 'spider', 'preview'], ignoreCase: true);
    }
}
