<?php

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * Paying (POST /checkout) and publishing (POST /create/{event}/publish) need a
 * verified email address; drafts never do. Password sign-ups get a link by
 * email (Fortify's emailVerification feature, GET /email/verify); Google and
 * email-code accounts are verified when they sign in.
 *
 * Accounts without any email address (none today: phone sign-in comes later)
 * have nothing to verify and pass.
 */
class EmailVerification
{
    /** The error key the React pages look for (components/account/VerifyEmailNotice.tsx). */
    public const ERROR = 'verify_email';

    public static function required(?User $user): bool
    {
        return $user !== null && filled($user->email) && ! $user->hasVerifiedEmail();
    }

    /**
     * Stop the request with a friendly prompt when the user still has to verify.
     * Inertia and form posts go back with the error (the page shows the resend
     * button); JSON callers get a 403 with the same message.
     *
     * @param  string  $action  "checkout" or "publish" (lang app.verify.blocked.*)
     *
     * @throws HttpResponseException
     */
    public static function ensure(Request $request, string $action): void
    {
        if (! static::required($request->user())) {
            return;
        }

        $message = trans("app.verify.blocked.{$action}", ['email' => $request->user()->email]);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            throw new HttpResponseException(response()->json(['message' => $message, 'errors' => [self::ERROR => [$message]]], 403));
        }

        throw new HttpResponseException(back()->withErrors([self::ERROR => $message]));
    }
}
