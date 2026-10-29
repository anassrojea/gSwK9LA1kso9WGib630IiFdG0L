<?php

namespace App\Http\Middleware;

use App\Services\Account\SessionRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes the signed-in user's session in SessionRegistry (at most once a minute),
 * after the request, so a login's regenerated session id is the one recorded.
 */
class TrackSession
{
    public function __construct(private SessionRegistry $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->hasSession() && ($user = $request->user())) {
            $this->sessions->touch($user, $request);
        }

        return $response;
    }
}
