<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', fn (User $user, string $id) => $user->getKey() === $id);

// RSVPs and check-ins for the host and venue dashboards (plan section 18)
Broadcast::channel('event.{event}', fn (User $user, Event $event) => $user->can('view', $event));
