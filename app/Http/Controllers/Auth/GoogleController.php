<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Account\GoogleAuth;
use App\Services\Account\GoogleSignInRefused;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * GET /auth/google/redirect and /auth/google/callback (Socialite).
 *
 * Signing in goes through Auth::login, so the Login event fires and guest
 * drafts follow the visitor into the account (AppServiceProvider). A signed-in
 * user coming back from Google is linking Google to the account (Security tab).
 */
class GoogleController extends Controller
{
    private const LINK_KEY = 'account.google_link';

    public function redirect(Request $request): Response
    {
        abort_unless(GoogleAuth::enabled(), 404);

        $request->session()->put(self::LINK_KEY, $request->user() !== null);

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, GoogleAuth $google): RedirectResponse
    {
        abort_unless(GoogleAuth::enabled(), 404);

        $linking = (bool) $request->session()->pull(self::LINK_KEY, false) && $request->user() !== null;

        // the person pressed "Cancel" on Google's consent screen
        if ($request->filled('error')) {
            return $this->fail($linking, 'cancelled');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return $this->fail($linking, 'state');
        } catch (Throwable $e) {
            Log::warning('Google sign-in failed', ['error' => $e->getMessage()]);

            return $this->fail($linking, 'failed');
        }

        try {
            if ($linking) {
                $google->link($request->user(), $googleUser);
                AuditLog::record('user.google_linked', $request->user(), [], $request->user());

                return redirect()->route('account.security')->with('status', trans('app.account.security.google_linked'));
            }

            [$user, $created] = $google->resolve($googleUser);
        } catch (GoogleSignInRefused $e) {
            return $this->fail($linking, $e->reason);
        }

        if ($created) {
            event(new Registered($user));
        }

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(config('fortify.home'));
    }

    private function fail(bool $linking, string $reason): RedirectResponse
    {
        $message = trans("app.auth.google.errors.{$reason}");

        return $linking
            ? redirect()->route('account.security')->with('warning', $message)
            : redirect()->route('login')->with('warning', $message);
    }
}
