<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * Who may edit an event in the creation wizard: the one place for it.
 *
 * Logged-in users go through EventPolicy::update. Visitors without an
 * account can start a draft (plan section 3, "No account until it matters"):
 * the draft gets a random key in events.anon_session_id and the session
 * keeps {event id => key}. The session id itself is never used, because
 * Laravel regenerates it on login; the session data survives that, so the
 * drafts can be attached to the account on Login / Registered.
 */
class DraftAccess
{
    public const SESSION_KEY = 'wizard.drafts';

    public function __construct(private Session $session) {}

    public function allows(?User $user, Event $event): bool
    {
        if ($user && $user->can('update', $event)) {
            return true;
        }

        return $this->holdsGuestDraft($event);
    }

    /**
     * True when this browser session created the (still unclaimed) draft.
     */
    public function holdsGuestDraft(Event $event): bool
    {
        if ($event->owner_id !== null || ! $event->anon_session_id) {
            return false;
        }

        $key = $this->session->get(self::SESSION_KEY.'.'.$event->getKey());

        return is_string($key) && hash_equals($event->anon_session_id, $key);
    }

    /**
     * Mark a new event as an anonymous draft of this session.
     */
    public function claimForSession(Event $event): void
    {
        $key = Str::random(48);
        $event->anon_session_id = $key;
        $event->owner_id = null;

        $this->session->put(self::SESSION_KEY.'.'.$event->getKey(), $key);
    }

    /**
     * Hand every draft this session created to the user who just logged in or registered.
     *
     * @return int number of drafts attached
     */
    public function attachTo(User $user): int
    {
        $drafts = $this->session->get(self::SESSION_KEY, []);

        if (! is_array($drafts) || $drafts === []) {
            return 0;
        }

        $attached = 0;

        Event::query()
            ->whereIn('id', array_keys($drafts))
            ->whereNull('owner_id')
            ->whereNotNull('anon_session_id')
            ->get()
            ->each(function (Event $event) use ($drafts, $user, &$attached) {
                if (is_string($drafts[$event->getKey()] ?? null) && hash_equals($event->anon_session_id, $drafts[$event->getKey()])) {
                    $event->forceFill(['owner_id' => $user->getKey(), 'anon_session_id' => null])->save();
                    $attached++;
                }
            });

        $this->session->forget(self::SESSION_KEY);

        return $attached;
    }
}
