<?php

namespace App\Services\Door;

use App\Models\AuditLog;
use App\Models\DoorPass;
use App\Models\Event;
use App\Models\User;
use App\Services\EventDay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Door PIN links for staff without an account (plan section 14.8).
 *
 * Two factors: a signed URL (cannot be guessed or altered, stops working at
 * the end of the event day) and a 6-digit PIN the host passes on separately.
 * Only a bcrypt hash of the PIN is stored and it is shown once. Wrong PINs
 * are rate-limited per link and device, and the link locks itself after
 * config('event_day.door.lock_after') of them. The host can revoke a link
 * at any time; every request re-checks it, so revoking ends open sessions.
 * Creating, unlocking, locking and revoking are written to the audit log.
 */
class DoorPasses
{
    public const SESSION_KEY = 'door_passes';

    /**
     * @return array{pass: DoorPass, pin: string, url: string}
     *
     * @throws ValidationException
     */
    public function create(Event $event, User $by, string $label): array
    {
        $until = EventDay::doorUntil($event);
        if ($until === null || $until->isPast()) {
            throw ValidationException::withMessages(['label' => trans('door.pass.event_over')]);
        }
        if ($event->doorPasses()->active()->count() >= (int) config('event_day.door.max_passes')) {
            throw ValidationException::withMessages(['label' => trans('door.pass.too_many')]);
        }

        $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $pass = $event->doorPasses()->create([
            'created_by' => $by->getKey(),
            'label' => $label,
            'pin_hash' => Hash::make($pin),
            // stored in the app's time zone: timestamps are written without an offset
            'expires_at' => $until->setTimezone(config('app.timezone')),
        ]);

        AuditLog::record('door_pass.created', $pass, ['event_id' => $event->getKey(), 'label' => $label, 'expires_at' => $until->toIso8601String()], $by);

        return ['pass' => $pass, 'pin' => $pin, 'url' => $this->url($pass)];
    }

    public function url(DoorPass $pass): string
    {
        return URL::temporarySignedRoute('door.pass', $pass->expires_at, ['event' => $pass->event_id, 'pass' => $pass->getKey()]);
    }

    public function revoke(DoorPass $pass, ?User $by, string $reason = 'host'): void
    {
        if ($pass->revoked_at !== null) {
            return;
        }

        $pass->forceFill(['revoked_at' => now(), 'revoked_reason' => $reason])->save();
        AuditLog::record($reason === 'host' ? 'door_pass.revoked' : 'door_pass.locked', $pass, ['event_id' => $pass->event_id, 'reason' => $reason], $by);
    }

    /**
     * Check a PIN typed on the door device and remember the link in its session.
     *
     * @throws ValidationException wrong PIN, too many tries, or the link no longer works
     */
    public function unlock(Request $request, DoorPass $pass, string $pin): void
    {
        if (! $pass->isActive()) {
            throw ValidationException::withMessages(['pin' => trans('door.pass.inactive')]);
        }

        $key = 'door-pin:'.$pass->getKey().'|'.$request->ip();
        $perMinute = (int) config('event_day.door.attempts_per_minute');
        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            throw ValidationException::withMessages(['pin' => trans('door.pass.slow_down', ['seconds' => RateLimiter::availableIn($key)])])->status(429);
        }

        if (! preg_match('/^\d{6}$/', $pin) || ! Hash::check($pin, $pass->pin_hash)) {
            RateLimiter::hit($key, 60);
            $pass->increment('failed_attempts');
            if ($pass->failed_attempts >= (int) config('event_day.door.lock_after')) {
                $this->revoke($pass, null, 'too_many_attempts');
                throw ValidationException::withMessages(['pin' => trans('door.pass.locked')]);
            }
            throw ValidationException::withMessages(['pin' => trans('door.pass.wrong_pin')]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY.'.'.$pass->event_id, $pass->getKey());
        $pass->forceFill(['last_used_at' => now()])->save();

        AuditLog::record('door_pass.unlocked', $pass, ['event_id' => $pass->event_id, 'ip_hash' => substr(hash('sha256', (string) $request->ip()), 0, 16)]);
    }

    /**
     * The door link this browser unlocked for the event, if it still works.
     */
    public function fromSession(Request $request, Event $event): ?DoorPass
    {
        if (! $request->hasSession()) {
            return null;
        }

        $id = $request->session()->get(self::SESSION_KEY.'.'.$event->getKey());
        if (! is_string($id)) {
            return null;
        }

        $pass = DoorPass::query()->whereKey($id)->where('event_id', $event->getKey())->first();
        if (! $pass?->isActive()) {
            $request->session()->forget(self::SESSION_KEY.'.'.$event->getKey());

            return null;
        }

        return $pass;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Event $event): array
    {
        return $event->doorPasses()
            ->latest()
            ->withCount('checkins')
            ->limit(30)
            ->get()
            ->map(fn (DoorPass $p) => [
                'id' => $p->id,
                'label' => $p->label,
                'active' => $p->isActive(),
                'expires_at' => $p->expires_at->toIso8601String(),
                'revoked_at' => $p->revoked_at?->toIso8601String(),
                'revoked_reason' => $p->revoked_reason,
                'last_used_at' => $p->last_used_at?->toIso8601String(),
                'checkins' => $p->checkins_count,
                // the link alone is useless without the PIN, which is never shown again
                'url' => $p->isActive() ? $this->url($p) : null,
            ])
            ->all();
    }
}
