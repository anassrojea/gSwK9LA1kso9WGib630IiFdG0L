<?php

namespace App\Http\Controllers\Journey;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET|POST /unsubscribe/{user} (signed, from the anniversary email): no more
 * marketing email for this account (users.marketing_opt_out). The POST is the
 * one-click List-Unsubscribe of RFC 8058; both work without signing in.
 */
class UnsubscribeController extends Controller
{
    public function __invoke(Request $request, User $user): Response
    {
        if (! $user->marketing_opt_out) {
            $user->forceFill(['marketing_opt_out' => true])->save();
            AuditLog::record('user.marketing_opt_out', $user, ['via' => $request->isMethod('post') ? 'one_click' : 'link'], $user);
        }

        app()->setLocale($user->preferredLocale());

        return response()->view('journey.unsubscribed', ['locale' => $user->preferredLocale()])
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
