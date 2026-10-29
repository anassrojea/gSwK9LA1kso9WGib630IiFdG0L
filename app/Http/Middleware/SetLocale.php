<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the locale (?lang= → session → user → default Arabic) and shares the
 * text direction so layouts render dir="rtl" for Arabic. Accept-Language is
 * deliberately ignored: many phones in the region are set to English, and
 * the product is Arabic first with an explicit English toggle.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = static::resolve($request);

        static::apply($locale);

        if ($request->query('lang') === $locale && $request->hasSession()) {
            $request->session()->put('locale', $locale);
        }

        return $next($request);
    }

    public static function supported(): array
    {
        return array_keys(config('app.locales'));
    }

    public static function direction(?string $locale = null): string
    {
        return config('app.locales')[$locale ?? App::getLocale()] ?? 'ltr';
    }

    public static function apply(string $locale): void
    {
        App::setLocale($locale);
        View::share('locale', $locale);
        View::share('dir', static::direction($locale));
    }

    private static function resolve(Request $request): string
    {
        $supported = static::supported();

        $candidates = [
            $request->query('lang'),
            $request->hasSession() ? $request->session()->get('locale') : null,
            $request->user()?->locale,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, $supported, true)) {
                return $candidate;
            }
        }

        return config('app.locale');
    }
}
