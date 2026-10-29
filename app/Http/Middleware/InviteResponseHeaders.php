<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guest invitation routes: never indexed (plan section 20), and no cookies so
 * Cloudflare can cache the HTML at the edge (plan section 21).
 */
class InviteResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Only responses that opted in (the invitation HTML) are cacheable; JSON stays private.
        if (! $request->isMethodCacheable() || ! $response->headers->hasCacheControlDirective('public')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
