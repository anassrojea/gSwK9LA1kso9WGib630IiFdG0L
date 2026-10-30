<?php

namespace App\Services\Org;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Organization;
use App\Models\OrgInvitation;
use App\Models\OrgMember;
use App\Models\User;
use App\Notifications\OrgInvited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The organization's team (plan section 4.2 "Team members"), phase 8A.
 *
 *   invite        by email with a role (staff | door), or "client" for one event; a signed
 *                 link (/org-invitations/{id}/{token}) valid config('org.invitation_days') days
 *   accept        by the signed-in user whose email it was sent to: on the link itself, or
 *                 right after logging in or registering (the token waits in the session)
 *   seats         members (owner included) plus pending team invitations ≤ the plan's team_seats
 *   roles         staff ↔ door; the owner changes only through transferOwnership, so an
 *                 organization always has exactly one owner, who can't leave or be removed
 *
 * A client invitation makes the accepting user the event's owner_id, keeping its
 * org_id: the client edits their invitation and sees only their event, the
 * organization keeps seeing it through org_id (EventPolicy). See docs/VENUE_PRO.md.
 */
class OrgTeam
{
    public const SESSION_KEY = 'org_invitation';

    public function __construct(private OrgPlans $plans) {}

    /**
     * Members plus pending team invitations (client invitations take no seat).
     */
    public function seatsUsed(Organization $org, ?OrgInvitation $except = null): int
    {
        $members = OrgMember::query()->where('org_id', $org->getKey())->count();
        $pending = OrgInvitation::query()
            ->where('org_id', $org->getKey())
            ->where('role', '!=', OrgInvitation::ROLE_CLIENT)
            ->pending()
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->count();

        return $members + $pending;
    }

    public function seatLimit(Organization $org): int
    {
        return $this->plans->limits($org)['team_seats'];
    }

    /**
     * @return array{0: OrgInvitation, 1: string} the invitation and its plain token (only in the email)
     */
    public function invite(Organization $org, User $inviter, string $email, string $role, ?Event $event = null): array
    {
        $email = Str::lower(trim($email));

        if (! in_array($role, OrgInvitation::ROLES, true)) {
            throw ValidationException::withMessages(['role' => trans('org.team.errors.role')]);
        }
        if ($role === OrgInvitation::ROLE_CLIENT && (! $event || $event->org_id !== $org->getKey())) {
            throw ValidationException::withMessages(['email' => trans('org.clients.errors.event')]);
        }

        return DB::transaction(function () use ($org, $inviter, $email, $role, $event) {
            Organization::query()->whereKey($org->getKey())->lockForUpdate()->first();

            if ($role !== OrgInvitation::ROLE_CLIENT) {
                $member = User::query()->whereRaw('lower(email) = ?', [$email])->first();
                if ($member && $member->roleIn($org) !== null) {
                    throw ValidationException::withMessages(['email' => trans('org.team.errors.already_member')]);
                }
                if ($this->seatsUsed($org) >= $this->seatLimit($org)) {
                    throw ValidationException::withMessages(['email' => trans('org.team.errors.seats', ['limit' => $this->seatLimit($org)])]);
                }
            }

            // a new invitation for the same person (and event) replaces the old one
            OrgInvitation::query()
                ->where('org_id', $org->getKey())
                ->where('email', $email)
                ->where('event_id', $event?->getKey())
                ->pending()
                ->update(['revoked_at' => now()]);

            $token = Str::random(48);
            $invitation = OrgInvitation::query()->create([
                'org_id' => $org->getKey(),
                'event_id' => $role === OrgInvitation::ROLE_CLIENT ? $event?->getKey() : null,
                'email' => $email,
                'role' => $role,
                'token_hash' => OrgInvitation::hashToken($token),
                'invited_by' => $inviter->getKey(),
                'expires_at' => now()->addDays((int) config('org.invitation_days', 7)),
            ]);

            AuditLog::record('org.invited', $org, ['invitation_id' => $invitation->getKey(), 'email' => $email, 'role' => $role, 'event_id' => $invitation->event_id], $inviter);

            DB::afterCommit(fn () => Notification::route('mail', $email)->notify(new OrgInvited($invitation, static::url($invitation, $token), app()->getLocale())));

            return [$invitation, $token];
        });
    }

    /**
     * The signed link in the email; its expiry is the invitation's own (shown on the page, not a 403).
     */
    public static function url(OrgInvitation $invitation, string $token): string
    {
        return URL::signedRoute('org.invitations.show', ['invitation' => $invitation->getKey(), 'token' => $token]);
    }

    public function find(string $id, string $token): ?OrgInvitation
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $invitation = OrgInvitation::query()->with(['organization', 'event'])->find($id);

        return $invitation && hash_equals($invitation->token_hash, OrgInvitation::hashToken($token)) ? $invitation : null;
    }

    /**
     * Accept for $user. Returns what was joined, or throws with the reason.
     */
    public function accept(OrgInvitation $invitation, User $user): OrgInvitation
    {
        return DB::transaction(function () use ($invitation, $user) {
            $invitation = OrgInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();
            $org = Organization::query()->whereKey($invitation->org_id)->lockForUpdate()->firstOrFail();

            if ($invitation->accepted_at && $invitation->accepted_by === $user->getKey()) {
                return $invitation;
            }
            if (! $invitation->isPending()) {
                throw ValidationException::withMessages(['invitation' => trans($invitation->revoked_at ? 'org.invite.errors.revoked' : ($invitation->accepted_at ? 'org.invite.errors.used' : 'org.invite.errors.expired'))]);
            }
            if (Str::lower((string) $user->email) !== $invitation->email) {
                throw ValidationException::withMessages(['invitation' => trans('org.invite.errors.email', ['email' => $invitation->email])]);
            }

            if ($invitation->isClient()) {
                $event = Event::query()->whereKey($invitation->event_id)->lockForUpdate()->first();
                if (! $event || $event->org_id !== $org->getKey()) {
                    throw ValidationException::withMessages(['invitation' => trans('org.invite.errors.expired')]);
                }
                $previous = $event->owner_id;
                $event->forceFill(['owner_id' => $user->getKey()])->save();
                AuditLog::record('org.client_joined', $event, ['org_id' => $org->getKey(), 'previous_owner' => $previous], $user);
            } elseif ($user->roleIn($org) === null) {
                if ($this->seatsUsed($org, except: $invitation) >= $this->seatLimit($org)) {
                    throw ValidationException::withMessages(['invitation' => trans('org.team.errors.seats', ['limit' => $this->seatLimit($org)])]);
                }
                OrgMember::query()->create(['org_id' => $org->getKey(), 'user_id' => $user->getKey(), 'role' => $invitation->role]);
                AuditLog::record('org.member_joined', $org, ['user_id' => $user->getKey(), 'role' => $invitation->role], $user);
            }

            $invitation->update(['accepted_at' => now(), 'accepted_by' => $user->getKey()]);

            return $invitation;
        });
    }

    /**
     * After login / registration: the invitation link the visitor opened before signing in.
     */
    public function acceptFromSession(User $user): ?OrgInvitation
    {
        if (! request()->hasSession() || ! ($pending = request()->session()->pull(self::SESSION_KEY))) {
            return null;
        }

        $invitation = $this->find((string) ($pending['id'] ?? ''), (string) ($pending['token'] ?? ''));
        if (! $invitation) {
            return null;
        }

        try {
            $accepted = $this->accept($invitation, $user);
            request()->session()->flash('status', trans($accepted->isClient() ? 'org.invite.client_joined' : 'org.invite.joined', ['org' => $invitation->organization->name]));

            return $accepted;
        } catch (ValidationException $e) {
            request()->session()->flash('warning', collect($e->errors())->flatten()->first());

            return null;
        }
    }

    public function revoke(OrgInvitation $invitation, User $actor): void
    {
        if ($invitation->isPending()) {
            $invitation->update(['revoked_at' => now()]);
            AuditLog::record('org.invitation_revoked', $invitation->organization, ['invitation_id' => $invitation->getKey(), 'email' => $invitation->email], $actor);
        }
    }

    public function changeRole(Organization $org, User $member, string $role, User $actor): void
    {
        if (! in_array($role, [OrgMember::ROLE_STAFF, OrgMember::ROLE_DOOR], true)) {
            throw ValidationException::withMessages(['role' => trans('org.team.errors.role')]);
        }

        DB::transaction(function () use ($org, $member, $role, $actor) {
            $row = $this->membership($org, $member);
            if ($row->role === OrgMember::ROLE_OWNER) {
                throw ValidationException::withMessages(['role' => trans('org.team.errors.owner_role')]);
            }
            $from = $row->role;
            OrgMember::query()->where(['org_id' => $org->getKey(), 'user_id' => $member->getKey()])->update(['role' => $role, 'updated_at' => now()]);
            AuditLog::record('org.role_changed', $org, ['user_id' => $member->getKey(), 'from' => $from, 'to' => $role], $actor);
        });
    }

    public function remove(Organization $org, User $member, User $actor): void
    {
        DB::transaction(function () use ($org, $member, $actor) {
            $row = $this->membership($org, $member);
            if ($row->role === OrgMember::ROLE_OWNER) {
                throw ValidationException::withMessages(['member' => trans('org.team.errors.remove_owner')]);
            }
            OrgMember::query()->where(['org_id' => $org->getKey(), 'user_id' => $member->getKey()])->delete();
            AuditLog::record($actor->is($member) ? 'org.member_left' : 'org.member_removed', $org, ['user_id' => $member->getKey(), 'role' => $row->role], $actor);
        });
    }

    /**
     * Leaving: anyone but the owner, who hands the organization over first.
     */
    public function leave(Organization $org, User $user): void
    {
        $this->remove($org, $user, $user);
    }

    /**
     * The owner hands the organization to another member, who becomes the
     * owner; the old owner stays on as staff. Exactly one owner before and after.
     */
    public function transferOwnership(Organization $org, User $from, User $to): void
    {
        DB::transaction(function () use ($org, $from, $to) {
            Organization::query()->whereKey($org->getKey())->lockForUpdate()->first();

            $current = $this->membership($org, $from);
            if ($current->role !== OrgMember::ROLE_OWNER) {
                throw ValidationException::withMessages(['user_id' => trans('org.team.errors.not_owner')]);
            }
            if ($from->is($to)) {
                throw ValidationException::withMessages(['user_id' => trans('org.team.errors.already_owner')]);
            }
            $this->membership($org, $to);

            OrgMember::query()->where(['org_id' => $org->getKey(), 'user_id' => $from->getKey()])->update(['role' => OrgMember::ROLE_STAFF, 'updated_at' => now()]);
            OrgMember::query()->where(['org_id' => $org->getKey(), 'user_id' => $to->getKey()])->update(['role' => OrgMember::ROLE_OWNER, 'updated_at' => now()]);

            $owners = OrgMember::query()->where('org_id', $org->getKey())->where('role', OrgMember::ROLE_OWNER)->count();
            if ($owners !== 1) {
                throw new \LogicException("Organization {$org->getKey()} would have {$owners} owners.");
            }

            AuditLog::record('org.ownership_transferred', $org, ['from' => $from->getKey(), 'to' => $to->getKey()], $from);
        });
    }

    private function membership(Organization $org, User $user): OrgMember
    {
        $row = OrgMember::query()->where('org_id', $org->getKey())->where('user_id', $user->getKey())->lockForUpdate()->first();
        if (! $row) {
            throw ValidationException::withMessages(['user_id' => trans('org.team.errors.not_member')]);
        }

        return $row;
    }
}
