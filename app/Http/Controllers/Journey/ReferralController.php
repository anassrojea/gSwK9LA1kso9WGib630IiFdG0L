<?php

namespace App\Http\Controllers\Journey;

use App\Http\Controllers\Controller;
use App\Services\Journey\Referrals;
use App\Support\SiteUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /r/{code}: someone's referral link (phase 8D, plan section 14.7).
 * Remembers the code in a cookie for journey.referrals.cookie_days (the
 * sign-up reads it: ReferralSignups) and sends the visitor to the landing
 * page. An unknown code still lands, without a cookie. A signed-in visitor
 * is never referred.
 */
class ReferralController extends Controller
{
    public function visit(Request $request, string $code, Referrals $referrals): RedirectResponse
    {
        $locale = $request->query('lang') === 'en' ? 'en' : 'ar';
        $query = http_build_query(['utm_source' => 'referral', 'utm_medium' => 'link', 'utm_campaign' => 'invite_friends']);
        $response = redirect()->away(SiteUrl::to('home', $locale).'?'.$query)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');

        $referrer = Referrals::enabled() ? $referrals->referrer($code) : null;
        if ($referrer && ! $request->user()) {
            $response->withCookie($referrals->cookie($referrer));
        }

        return $response;
    }
}
