<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrgMember;
use App\Models\User;

class OrganizationPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Organization $organization): bool
    {
        return $user->roleIn($organization) !== null;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->roleIn($organization) === OrgMember::ROLE_OWNER;
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $user->roleIn($organization) === OrgMember::ROLE_OWNER;
    }

    /**
     * Owners and staff create client events and hall layouts; door staff only scan.
     */
    public function createEvents(User $user, Organization $organization): bool
    {
        return in_array($user->roleIn($organization), [OrgMember::ROLE_OWNER, OrgMember::ROLE_STAFF], true);
    }

    /**
     * The subscription (plans, checkout, cancel, the Billing Portal) is the owner's: the owner pays (phase 8A).
     */
    public function manageBilling(User $user, Organization $organization): bool
    {
        return $user->roleIn($organization) === OrgMember::ROLE_OWNER;
    }

    /**
     * Anyone but the owner can leave; the owner hands the organization over first.
     */
    public function leave(User $user, Organization $organization): bool
    {
        $role = $user->roleIn($organization);

        return $role !== null && $role !== OrgMember::ROLE_OWNER;
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $user->roleIn($organization) === OrgMember::ROLE_OWNER;
    }
}
