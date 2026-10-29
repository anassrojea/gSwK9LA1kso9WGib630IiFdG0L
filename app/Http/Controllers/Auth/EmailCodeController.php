<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginCode;
use App\Models\User;
use App\Services\Account\LoginCodes;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Passwordless sign-in (plan sections 3 and 15.1): "Email me a code".
 *
 *   POST /login/code         {email}              sends a code (same answer for every address)
 *   POST /login/code/verify  {email, code, name?} signs in, creating the account if there is none
 *   POST /login/code/reset                         back to the email step
 */
class EmailCodeController extends Controller
{
    public const SESSION_KEY = 'login_code.email';

    public function send(Request $request, LoginCodes $codes): RedirectResponse
    {
        abort_unless(LoginCodes::enabled(), 404);

        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $email = LoginCodes::normalize($data['email']);

        $codes->send($email, LoginCode::LOGIN, $request->ip());

        $request->session()->put(self::SESSION_KEY, $email);

        return back()->with('status', trans('app.auth.code.sent', ['email' => $email]));
    }

    public function verify(Request $request, LoginCodes $codes): RedirectResponse
    {
        abort_unless(LoginCodes::enabled(), 404);

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:255'],
            'remember' => ['nullable', 'boolean'],
        ]);
        $email = LoginCodes::normalize($data['email']);

        $codes->verify($email, LoginCode::LOGIN, $data['code']);

        [$user, $created] = DB::transaction(function () use ($email, $data) {
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->lockForUpdate()->first();

            if ($user) {
                // the code proves the person reads this inbox
                if (! $user->email_verified_at) {
                    $user->forceFill(['email_verified_at' => now()])->save();
                }

                return [$user, false];
            }

            $user = new User([
                'name' => Str::limit(trim((string) ($data['name'] ?? '')) ?: self::nameFromEmail($email), 255, ''),
                'email' => $email,
                'locale' => app()->getLocale(),
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            return [$user, true];
        });

        $request->session()->forget(self::SESSION_KEY);

        if ($created) {
            event(new Registered($user));
        }

        Auth::guard('web')->login($user, (bool) ($data['remember'] ?? false));
        $request->session()->regenerate();

        return redirect()->intended(config('fortify.home'));
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return back();
    }

    /**
     * "sara.ahmad@example.com" → "Sara Ahmad"
     */
    public static function nameFromEmail(string $email): string
    {
        $local = Str::before($email, '@');
        $name = trim((string) preg_replace('/[._\-+\d]+/', ' ', $local));

        return $name === '' ? $local : Str::title($name);
    }
}
