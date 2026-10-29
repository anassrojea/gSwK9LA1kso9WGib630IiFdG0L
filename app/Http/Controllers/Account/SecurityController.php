<?php

namespace App\Http\Controllers\Account;

use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Account\SessionRegistry;
use App\Services\Digits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Account → Security.
 *
 *   PUT    /account/password        change it (current password) or set a first one
 *   DELETE /account/google          unlink Google, unless it is the only way to sign in
 *   DELETE /account/sessions        sign out every other device
 *   DELETE /account/sessions/{key}  sign out one device
 */
class SecurityController extends Controller
{
    public function password(Request $request, UpdateUserPassword $action): RedirectResponse
    {
        $user = $request->user();
        $hadPassword = $user->hasPassword();

        $action->update($user, $request->only(['current_password', 'password', 'password_confirmation']));
        AuditLog::record($hadPassword ? 'user.password_changed' : 'user.password_set', $user, [], $user);

        return back()->with('status', trans($hadPassword ? 'app.account.security.password_changed' : 'app.account.security.password_set'));
    }

    public function unlinkGoogle(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->canUnlinkGoogle()) {
            return back()->with('warning', trans('app.account.security.google_only_method'));
        }

        $user->forceFill(['google_id' => null])->save();
        AuditLog::record('user.google_unlinked', $user, [], $user);

        return back()->with('status', trans('app.account.security.google_unlinked'));
    }

    public function signOutOthers(Request $request, SessionRegistry $sessions): RedirectResponse
    {
        $count = $sessions->signOut($request->user(), $request);

        return back()->with('status', trans('app.account.security.sessions.signed_out', ['count' => Digits::localize($count)]));
    }

    public function signOut(Request $request, SessionRegistry $sessions, string $key): RedirectResponse
    {
        $sessions->signOut($request->user(), $request, $key);

        return back()->with('status', trans('app.account.security.sessions.signed_out_one'));
    }
}
