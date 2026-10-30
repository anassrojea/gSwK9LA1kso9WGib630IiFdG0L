<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public site pages carry their language in the URL (/pricing is Arabic,
 * /en/pricing English) so search engines index both (hreflang). The URL wins
 * over the session, and the choice is remembered for the rest of the app
 * (the wizard, the dashboard) like the /locale switch.
 */
class SetSiteLocale
{
    public function handle(Request $request, Closure $next, string $locale = 'ar'): Response
    {
        if (! in_array($locale, SetLocale::supported(), true)) {
            $locale = config('app.locale');
        }

        SetLocale::apply($locale);

        if ($request->hasSession()) {
            $request->session()->put('locale', $locale);
        }

        return $next($request);
    }
}
