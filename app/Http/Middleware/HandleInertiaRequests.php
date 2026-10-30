<?php

namespace App\Http\Middleware;

use App\Services\Account\AccountDeletion;
use App\Services\Account\EmailVerification;
use App\Services\Account\GoogleAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $locale = App::getLocale();

        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'auth' => [
                'user' => ($user = $request->user()) ? [
                    ...$user->only(['id', 'name', 'email', 'locale', 'role']),
                    // false: paying and publishing show the "verify your email" prompt (EmailVerification)
                    'email_verified' => ! EmailVerification::required($user),
                    // the grace banner (Account → Privacy) shows on every page while a deletion is pending
                    'deletion_scheduled_for' => AccountDeletion::scheduledFor($user)?->toIso8601String(),
                ] : null,
                // sign-in buttons: Google only when GOOGLE_CLIENT_ID is configured
                'providers' => ['google' => GoogleAuth::enabled()],
            ],
            'locale' => $locale,
            'dir' => SetLocale::direction($locale),
            'locales' => SetLocale::supported(),
            // flat dictionary for the React pages (react-i18next can take over once the wizard lands)
            // plus lang/{locale}/invoices.php under "invoices" (download links on orders and billing)
            // and the album and AI dictionaries (phase 7B), read as t('album.host.…') / t('ai.…')
            'translations' => fn () => trans('app', [], $locale) + [
                'invoices' => trans('invoices', [], $locale),
                'album' => trans('album', [], $locale),
                'ai' => trans('ai', [], $locale),
                // Venue & Planner Pro (phase 8A): t('org.…')
                'org' => trans('org', [], $locale),
                // phase 8C: template marketplace and vendor listings, read as t('marketplace.…') / t('vendors.…')
                'marketplace' => trans('marketplace', [], $locale),
                'vendors' => trans('vendors', [], $locale),
            ],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
        ];
    }
}
