<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel speaks the admin's own language (users.locale, Arabic by
 * default); before signing in, the session's choice. Filament ships Arabic
 * strings and switches to RTL from them. The switch in the user menu goes
 * through GET /locale/{locale}, which saves users.locale.
 * Registered as persistent panel middleware so Livewire requests match.
 */
class SetAdminLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = SetLocale::supported();

        $locale = collect([
            $request->user()?->locale,
            $request->hasSession() ? $request->session()->get('locale') : null,
        ])->first(fn ($candidate) => is_string($candidate) && in_array($candidate, $supported, true)) ?? 'ar';

        SetLocale::apply($locale);

        return $next($request);
    }
}
