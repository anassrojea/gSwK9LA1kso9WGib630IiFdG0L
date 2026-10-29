<?php

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * The signed-in devices of a user, for "Security → Sessions".
 *
 * Sessions live in Redis (SESSION_DRIVER=redis), which can't be searched by
 * user, so App\Http\Middleware\TrackSession keeps a small index per user in
 * the cache: session id => {ip, agent, last seen}. Signing a device out
 * destroys that session in the session handler (works for any driver) and
 * rotates the remember-me token so a "remember me" cookie can't sign it
 * back in. The page only ever sees a hash of each session id.
 */
class SessionRegistry
{
    /** seconds between two writes for the same session */
    public const TOUCH_EVERY = 60;

    public const MAX_SESSIONS = 20;

    public function touch(User $user, Request $request): void
    {
        $id = $request->session()->getId();
        $sessions = $this->all($user);
        $seen = $sessions[$id]['last_seen'] ?? 0;

        if (time() - $seen < self::TOUCH_EVERY) {
            return;
        }

        $sessions[$id] = [
            'ip' => $request->ip(),
            'agent' => Str::limit((string) $request->userAgent(), 300, ''),
            'last_seen' => time(),
        ];

        // newest first, capped
        uasort($sessions, fn (array $a, array $b) => $b['last_seen'] <=> $a['last_seen']);
        $this->save($user, array_slice($sessions, 0, self::MAX_SESSIONS, true));
    }

    public function forget(User $user, string $sessionId): void
    {
        $sessions = $this->all($user);
        unset($sessions[$sessionId]);
        $this->save($user, $sessions);
    }

    /**
     * For the page: the current session first.
     *
     * @return list<array{key: string, ip: ?string, agent: string, last_seen: string, current: bool}>
     */
    public function list(User $user, string $currentId): array
    {
        $handler = Session::getHandler();

        return collect($this->all($user))
            // expired or destroyed elsewhere (but the device itself is always listed)
            ->filter(fn (array $s, string $id) => $id === $currentId || $handler->read($id) !== '')
            ->map(fn (array $s, string $id) => [
                'key' => self::key($id),
                'ip' => $s['ip'] ?? null,
                'agent' => (string) ($s['agent'] ?? ''),
                'last_seen' => date(DATE_ATOM, (int) $s['last_seen']),
                'current' => $id === $currentId,
            ])
            ->sortByDesc(fn (array $s) => [$s['current'], $s['last_seen']])
            ->values()
            ->all();
    }

    /**
     * Sign out one session by its public key, or (null) every session but the current one.
     *
     * @return int sessions signed out
     */
    public function signOut(User $user, Request $request, ?string $key = null): int
    {
        $current = $request->session()->getId();
        $handler = Session::getHandler();
        $sessions = $this->all($user);
        $count = 0;

        foreach (array_keys($sessions) as $id) {
            if ($id === $current || ($key !== null && ! hash_equals(self::key($id), $key))) {
                continue;
            }

            $handler->destroy($id);
            unset($sessions[$id]);
            $count++;
        }

        $this->save($user, $sessions);
        $this->rotateRememberToken($user, $request);

        return $count;
    }

    /**
     * Forget every session of a user (account purge).
     */
    public function destroyAll(User $user): void
    {
        $handler = Session::getHandler();
        foreach (array_keys($this->all($user)) as $id) {
            $handler->destroy($id);
        }
        $this->store()->forget($this->cacheKey($user));
    }

    public static function key(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 32);
    }

    /**
     * Remember-me cookies on other devices stop working; this device keeps its own.
     */
    private function rotateRememberToken(User $user, Request $request): void
    {
        $guard = auth()->guard('web');
        $recaller = method_exists($guard, 'getRecallerName') ? $guard->getRecallerName() : null;

        $user->setRememberToken(Str::random(60));
        $user->save();

        if ($recaller && $request->cookies->has($recaller)) {
            Cookie::queue(Cookie::forever($recaller, $user->getAuthIdentifier().'|'.$user->getRememberToken().'|'.$user->getAuthPassword()));
        }
    }

    /**
     * @return array<string, array{ip: ?string, agent: string, last_seen: int}>
     */
    private function all(User $user): array
    {
        $lifetime = (int) config('session.lifetime', 120) * 60;
        $remember = 60 * 60 * 24 * 30;
        $cutoff = time() - max($lifetime, $remember);

        return array_filter(
            (array) $this->store()->get($this->cacheKey($user), []),
            fn ($s) => is_array($s) && ($s['last_seen'] ?? 0) >= $cutoff,
        );
    }

    private function save(User $user, array $sessions): void
    {
        $this->store()->put($this->cacheKey($user), $sessions, now()->addDays(31));
    }

    private function cacheKey(User $user): string
    {
        return 'account:sessions:'.$user->getKey();
    }

    private function store(): Repository
    {
        return Cache::store();
    }
}
