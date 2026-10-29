<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\Household;
use App\Models\User;

/**
 * Households follow their event. Guests never reach this policy: they use
 * the rate-limited token routes under /i/{token}.
 */
class HouseholdPolicy
{
    public function __construct(private EventPolicy $events) {}

    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user, Event $event): bool
    {
        return $this->events->view($user, $event);
    }

    public function view(User $user, Household $household): bool
    {
        return $this->events->view($user, $household->event);
    }

    public function create(User $user, Event $event): bool
    {
        return $this->events->update($user, $event);
    }

    public function update(User $user, Household $household): bool
    {
        return $this->events->update($user, $household->event);
    }

    public function delete(User $user, Household $household): bool
    {
        return $this->events->update($user, $household->event);
    }
}
