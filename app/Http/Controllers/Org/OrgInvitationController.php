<?php

namespace App\Http\Controllers\Org;

use App\Http\Controllers\Controller;
use App\Models\OrgInvitation;
use App\Services\Org\OrgTeam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The link in a team or client invitation email (signed, expires with the invitation):
 *
 *   GET  /org-invitations/{id}/{token}   who invites you and to what; signed in with the
 *                                        invited email: "Accept"; signed out: log in or create
 *                                        an account, and the invitation is accepted right after
 *                                        (OrgTeam::acceptFromSession, on the Login event)
 *   POST /org-invitations/{id}/{token}   accept
 */
class OrgInvitationController extends Controller
{
    public function __construct(private OrgTeam $team) {}

    public function show(Request $request, string $invitation, string $token): Response|RedirectResponse
    {
        $found = $this->team->find($invitation, $token);
        abort_unless($found, 404);

        $user = $request->user();
        if (! $user && $found->isPending()) {
            // accepted on the next login or registration in this browser
            $request->session()->put(OrgTeam::SESSION_KEY, ['id' => $found->getKey(), 'token' => $token]);
        }

        return Inertia::render('org/Invitation', [
            'invitation' => [
                'org' => $found->organization->name,
                'logo_url' => $found->organization->logo_url,
                'role' => $found->role,
                'event' => $found->isClient() ? $found->event?->title : null,
                'email' => $found->email,
                'inviter' => $found->inviter?->name,
                'expires_at' => $found->expires_at->toIso8601String(),
                'state' => $this->state($found),
            ],
            'signed_in_as' => $user?->email,
            'email_matches' => $user && Str::lower((string) $user->email) === $found->email,
            'accept_url' => $request->fullUrl(),
            'login_url' => route('login'),
            'register_url' => route('register', ['email' => $found->email]),
        ]);
    }

    public function accept(Request $request, string $invitation, string $token): RedirectResponse
    {
        $found = $this->team->find($invitation, $token);
        abort_unless($found, 404);

        try {
            $accepted = $this->team->accept($found, $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return $accepted->isClient() && $accepted->event_id
            ? redirect()->route('create.step', [$accepted->event_id, $accepted->event?->status === 'draft' ? 'details' : 'review'])->with('status', trans('org.invite.client_joined', ['org' => $found->organization->name]))
            : redirect()->route('org.show', $found->org_id)->with('status', trans('org.invite.joined', ['org' => $found->organization->name]));
    }

    private function state(OrgInvitation $invitation): string
    {
        return match (true) {
            $invitation->accepted_at !== null => 'accepted',
            $invitation->revoked_at !== null => 'revoked',
            $invitation->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }
}
