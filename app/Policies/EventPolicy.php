<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\OrgMember;
use App\Models\User;

/**
 * A host sees only their own events; organization members see their
 * organization's events (plan section 16). Admins may do anything; they
 * normally work through Filament.
 */
class EventPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Event $event): bool
    {
        return $this->owns($user, $event) || $this->orgRole($user, $event) !== null;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Event $event): bool
    {
        return $this->owns($user, $event)
            || in_array($this->orgRole($user, $event), [OrgMember::ROLE_OWNER, OrgMember::ROLE_STAFF], true);
    }

    public function delete(User $user, Event $event): bool
    {
        return $this->owns($user, $event) || $this->orgRole($user, $event) === OrgMember::ROLE_OWNER;
    }

    /**
     * Door staff can scan guests in but not edit the event.
     */
    public function checkIn(User $user, Event $event): bool
    {
        return $this->view($user, $event);
    }

    private function owns(User $user, Event $event): bool
    {
        return $event->owner_id !== null && $event->owner_id === $user->getKey();
    }

    private function orgRole(User $user, Event $event): ?string
    {
        return $event->org_id ? $user->roleIn($event->org_id) : null;
    }
}
