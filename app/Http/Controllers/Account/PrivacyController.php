<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LoginCode;
use App\Services\Account\AccountDeletion;
use App\Services\Account\DataExport;
use App\Services\Account\LoginCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Account → Privacy (plan section 20).
 *
 *   GET    /account/export         the user's data as JSON (App\Services\Account\DataExport)
 *   POST   /account/deletion/code  a confirmation code by email, for accounts without a password
 *   POST   /account/deletion       {password | code}: start the grace period
 *   DELETE /account/deletion       cancel it
 */
class PrivacyController extends Controller
{
    public function export(Request $request, DataExport $export): Response
    {
        $user = $request->user();
        AuditLog::record('user.data_exported', $user, [], $user);

        return response(json_encode($export->build($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.DataExport::filename($user).'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function sendCode(Request $request, LoginCodes $codes): RedirectResponse
    {
        $user = $request->user();
        abort_if(blank($user->email), 422);

        $codes->send($user->email, LoginCode::DELETE, $request->ip(), $user->locale);

        return back()->with('status', trans('app.account.privacy.code_sent', ['email' => $user->email]));
    }

    public function request(Request $request, AccountDeletion $deletion, LoginCodes $codes): RedirectResponse
    {
        $user = $request->user();

        if ($blockers = $deletion->blockers($user)) {
            throw ValidationException::withMessages(['deletion' => trans('app.account.privacy.blocked.'.$blockers[0])]);
        }

        if ($user->hasPassword()) {
            $data = $request->validate(['password' => ['required', 'string']]);
            if (! Hash::check($data['password'], $user->getAuthPassword())) {
                throw ValidationException::withMessages(['password' => trans('app.account.errors.wrong_password')]);
            }
        } else {
            $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
            $codes->verify((string) $user->email, LoginCode::DELETE, $data['code']);
        }

        $deletion->request($user);

        return redirect()->route('account.privacy')->with('status', trans('app.account.privacy.requested', [
            'date' => AccountDeletion::formatDate(AccountDeletion::scheduledFor($user), app()->getLocale()),
        ]));
    }

    public function cancel(Request $request, AccountDeletion $deletion): RedirectResponse
    {
        $deletion->cancel($request->user());

        return back()->with('status', trans('app.account.privacy.cancelled'));
    }
}
