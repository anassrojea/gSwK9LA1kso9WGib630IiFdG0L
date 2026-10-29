<?php

namespace App\Http\Middleware;

use App\Models\Event;
use App\Models\Household;
use App\Services\DraftAccess;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wizard routes ({event} or {household} in the URL): the owner, org staff,
 * or the browser session that created a guest draft (see DraftAccess).
 * Anyone else gets 401 when logged out and 403 when logged in.
 */
class EnsureDraftAccess
{
    public function __construct(private DraftAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $event = $request->route('event');
        if (! $event instanceof Event) {
            $household = $request->route('household');
            $event = $household instanceof Household ? $household->event : null;
        }

        abort_unless($event instanceof Event, 404);

        if ($this->access->allows($request->user(), $event)) {
            return $next($request);
        }

        if (! $request->user()) {
            throw new AuthenticationException('Unauthenticated.', ['web']);
        }

        abort(403);
    }
}
