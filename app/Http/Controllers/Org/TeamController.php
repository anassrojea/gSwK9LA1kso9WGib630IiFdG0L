<?php

namespace App\Http\Controllers\Org;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrgInvitation;
use App\Models\OrgMember;
use App\Models\User;
use App\Services\Org\OrgPresenter;
use App\Services\Org\OrgTeam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /org/{org}/team (phase 8A): members and pending invitations. Inviting,
 * changing roles, removing and handing over ownership are the owner's
 * (OrganizationPolicy::manageMembers); anyone but the owner can leave.
 * The rules live in App\Services\Org\OrgTeam.
 */
class TeamController extends Controller
{
    public function __construct(private OrgTeam $team, private OrgPresenter $presenter) {}

    public function index(Request $request, Organization $organization): Response
    {
        Gate::authorize('view', $organization);
        $canManage = $request->user()->can('manageMembers', $organization);

        return Inertia::render('org/Team', [
            'org' => $this->presenter->present($organization, $request->user()),
            'members' => OrganizationController::team($organization),
            'invitations' => $canManage ? OrgInvitation::query()
                ->where('org_id', $organization->getKey())
                ->where('role', '!=', OrgInvitation::ROLE_CLIENT)
                ->pending()
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (OrgInvitation $i) => [
                    'id' => $i->id,
                    'email' => $i->email,
                    'role' => $i->role,
                    'expires_at' => $i->expires_at->toIso8601String(),
                ])->values() : [],
            'can_manage' => $canManage,
            'me' => $request->user()->getKey(),
        ]);
    }

    public function invite(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manageMembers', $organization);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in([OrgMember::ROLE_STAFF, OrgMember::ROLE_DOOR])],
        ]);

        $this->team->invite($organization, $request->user(), $data['email'], $data['role']);

        return back()->with('status', trans('org.team.invited', ['email' => $data['email']]));
    }

    public function revoke(Request $request, Organization $organization, OrgInvitation $invitation): RedirectResponse
    {
        Gate::authorize('manageMembers', $organization);
        abort_unless($invitation->org_id === $organization->getKey(), 404);

        $this->team->revoke($invitation, $request->user());

        return back()->with('status', trans('org.team.revoked'));
    }

    public function role(Request $request, Organization $organization, User $member): RedirectResponse
    {
        Gate::authorize('manageMembers', $organization);
        $data = $request->validate(['role' => ['required', Rule::in([OrgMember::ROLE_STAFF, OrgMember::ROLE_DOOR])]]);

        $this->team->changeRole($organization, $member, $data['role'], $request->user());

        return back()->with('status', trans('org.team.role_changed'));
    }

    public function remove(Request $request, Organization $organization, User $member): RedirectResponse
    {
        Gate::authorize('manageMembers', $organization);

        $this->team->remove($organization, $member, $request->user());

        return back()->with('status', trans('org.team.removed'));
    }

    public function transfer(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manageMembers', $organization);
        $data = $request->validate(['user_id' => ['required', 'uuid']]);
        $to = User::query()->findOrFail($data['user_id']);

        // an admin acting here hands over from the current owner
        $from = $request->user()->roleIn($organization) === OrgMember::ROLE_OWNER ? $request->user() : ($organization->owner() ?? abort(409));
        $this->team->transferOwnership($organization, $from, $to);

        return back()->with('status', trans('org.team.transferred', ['name' => $to->name]));
    }

    public function leave(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('leave', $organization);

        $this->team->leave($organization, $request->user());

        return redirect()->route('org.index')->with('status', trans('org.team.left', ['org' => $organization->name]));
    }
}
