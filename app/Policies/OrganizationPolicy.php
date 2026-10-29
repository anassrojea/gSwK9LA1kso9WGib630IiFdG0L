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

    public function delete(User $user, Organization $organization): bool
    {
        return $user->roleIn($organization) === OrgMember::ROLE_OWNER;
    }
}
