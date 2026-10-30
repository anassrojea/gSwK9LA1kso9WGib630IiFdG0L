<?php

namespace App\Services\Door;

use App\Models\DoorPass;
use App\Models\User;

/**
 * Who is scanning at the door: a signed-in user allowed by
 * EventPolicy::checkIn, or a door PIN link (staff without an account).
 */
final class DoorActor
{
    public function __construct(public readonly ?User $user = null, public readonly ?DoorPass $pass = null) {}

    public function label(): string
    {
        return $this->user?->name ?? trans('door.by_pass', ['label' => $this->pass?->label ?? '']);
    }

    public function canSubscribe(): bool
    {
        return $this->user !== null;
    }
}
