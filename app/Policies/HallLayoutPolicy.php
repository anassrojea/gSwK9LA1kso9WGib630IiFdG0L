<?php

namespace App\Policies;

use App\Models\HallLayout;
use App\Models\User;

/**
 * Hall layouts belong to a host (owner_id) or to an organization (org_id).
 * Any member of the organization may pick its halls for an event; owners and
 * staff edit them (OrganizationPolicy::createEvents), door staff only scan.
 */
class HallLayoutPolicy
{
    public function __construct(private OrganizationPolicy $organizations) {}

    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, HallLayout $hall): bool
    {
        if ($hall->owner_id !== null && $hall->owner_id === $user->getKey()) {
            return true;
        }

        return $hall->org_id !== null && $user->roleIn($hall->org_id) !== null;
    }

    public function update(User $user, HallLayout $hall): bool
    {
        if ($hall->owner_id !== null && $hall->owner_id === $user->getKey()) {
            return true;
        }

        return $hall->org_id !== null && $hall->organization !== null && $this->organizations->createEvents($user, $hall->organization);
    }

    public function delete(User $user, HallLayout $hall): bool
    {
        return $this->update($user, $hall);
    }
}
