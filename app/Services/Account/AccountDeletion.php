<?php

namespace App\Services\Account;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\LoginCode;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TransferReceipt;
use App\Models\User;
use App\Notifications\AccountDeleted;
use App\Notifications\AccountDeletionRequested;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use IntlDateFormatter;

/**
 * "Delete my account" (plan section 20: delete on request).
 *
 * request()  marks users.deletion_requested_at and emails the date; the
 *            account keeps working and the request can be cancelled.
 * purge()    runs from account:purge-deleted once the grace period is over:
 *
 *   kept, detached from the person (accounting needs them):
 *     orders           user_id set null; code, items, amounts, currency, status and dates stay
 *     payments         amounts stay; names, emails and addresses are removed from the raw gateway payloads
 *     transfer receipts the customer's own note is removed; the bank reference and decision stay
 *     audit log        rows stay with actor_id null; rows about the user lose their data
 *   deleted:
 *     the user, their events (and everything that cascades from them: invitations,
 *     households, RSVPs, reminders, open links, media rows, entitlements, ...),
 *     uploaded files and link-preview images on the media disk, org memberships,
 *     one-time codes, sessions, the Lemon Squeezy customer row
 *
 * Deletion is blocked while a transfer receipt waits for an admin (the money
 * may have been sent), and for the last admin.
 */
class AccountDeletion
{
    public function __construct(private SessionRegistry $sessions) {}

    public static function graceDays(): int
    {
        return max(0, (int) config('account.deletion_grace_days', 14));
    }

    public static function scheduledFor(User $user): ?CarbonImmutable
    {
        return $user->deletion_requested_at?->addDays(self::graceDays());
    }

    public static function formatDate(?CarbonImmutable $date, string $locale): string
    {
        if (! $date) {
            return '';
        }

        $formatter = new IntlDateFormatter(str_starts_with($locale, 'ar') ? 'ar@numbers=arab' : 'en_GB', IntlDateFormatter::LONG, IntlDateFormatter::NONE, config('app.timezone'));

        return (string) $formatter->format($date);
    }

    /**
     * Why this account can't be deleted now, as app.account.privacy.blocked.* keys.
     *
     * @return list<string>
     */
    public function blockers(User $user): array
    {
        $blockers = [];

        if (Order::query()->where('user_id', $user->getKey())->where('status', 'receipt_received')->exists()) {
            $blockers[] = 'pending_transfer';
        }

        if ($user->isAdmin() && ! User::query()
            ->where('role', User::ROLE_ADMIN)
            ->whereKeyNot($user->getKey())
            ->whereNull('deletion_requested_at')
            ->exists()) {
            $blockers[] = 'last_admin';
        }

        return $blockers;
    }

    public function request(User $user): void
    {
        if ($user->deletion_requested_at) {
            return;
        }

        $user->forceFill(['deletion_requested_at' => now()])->save();
        AuditLog::record('user.deletion_requested', $user, [], $user);

        $user->notify(new AccountDeletionRequested);
    }

    public function cancel(User $user): void
    {
        if (! $user->deletion_requested_at) {
            return;
        }

        $user->forceFill(['deletion_requested_at' => null])->save();
        AuditLog::record('user.deletion_cancelled', $user, [], $user);
    }

    /**
     * Users whose grace period is over.
     *
     * @return Builder<User>
     */
    public function due()
    {
        return User::query()
            ->whereNotNull('deletion_requested_at')
            ->where('deletion_requested_at', '<=', now()->subDays(self::graceDays()));
    }

    /**
     * Delete the account for good. Returns false (and changes nothing) when a blocker came up meanwhile.
     */
    public function purge(User $user): bool
    {
        if ($this->blockers($user) !== []) {
            Log::info('Account purge postponed', ['user' => $user->getKey(), 'blockers' => $this->blockers($user)]);

            return false;
        }

        $userId = $user->getKey();
        $email = $user->email;
        $name = $user->name;
        $locale = $user->preferredLocale();
        $eventIds = Event::query()->where('owner_id', $userId)->pluck('id')->all();

        DB::transaction(function () use ($user, $userId, $email, $eventIds) {
            $orderIds = Order::query()->where('user_id', $userId)->pluck('id');

            // unpaid orders can't be paid any more
            Order::query()->whereIn('id', $orderIds)->where('status', 'awaiting_payment')->update(['status' => 'expired', 'updated_at' => now()]);

            Payment::query()->whereIn('order_id', $orderIds)->whereNotNull('raw')->get()->each(function (Payment $payment) {
                $payment->forceFill(['raw' => self::scrub($payment->raw)])->save();
            });

            TransferReceipt::query()->whereIn('order_id', $orderIds)->update(['note' => null]);

            Order::query()->whereIn('id', $orderIds)->update(['user_id' => null]);

            AuditLog::query()->where('actor_id', $userId)->update(['actor_id' => null]);
            AuditLog::query()->where('entity', 'User')->where('entity_id', (string) $userId)->update(['data' => null]);

            if ($email) {
                LoginCode::query()->where('email', $email)->delete();
            }

            DB::table('lemon_squeezy_customers')->where('billable_type', $user->getMorphClass())->where('billable_id', $userId)->delete();

            // events cascade to invitations, households, RSVPs, media rows, entitlements, ...
            Event::query()->whereIn('id', $eventIds)->delete();

            $user->delete();

            AuditLog::query()->create([
                'actor_id' => null,
                'action' => 'user.purged',
                'entity' => 'User',
                'entity_id' => (string) $userId,
                'data' => ['events' => count($eventIds), 'orders_kept' => $orderIds->count()],
                'at' => now(),
            ]);
        });

        $this->deleteFiles($eventIds);
        $this->sessions->destroyAll($user);

        if ($email) {
            Notification::route('mail', $email)->notify((new AccountDeleted($name))->locale($locale));
        }

        return true;
    }

    /**
     * Uploads (events/{id}/...) and link-preview images (og/{id}/...) on the media disk.
     *
     * @param  list<string>  $eventIds
     */
    private function deleteFiles(array $eventIds): void
    {
        $disk = Storage::disk(config('filesystems.media_disk', 'public'));

        foreach ($eventIds as $id) {
            $disk->deleteDirectory("events/{$id}");
            $disk->deleteDirectory("og/{$id}");
        }
    }

    /** keys in gateway payloads that identify a person */
    private const PERSONAL_KEYS = [
        'email', 'customer_email', 'user_email', 'receipt_email', 'name', 'customer_name', 'user_name',
        'phone', 'address', 'billing_details', 'shipping', 'shipping_details', 'customer_details', 'card_last_four', 'last4',
    ];

    public static function scrub(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), self::PERSONAL_KEYS, true)) {
                $value[$key] = null;
            } else {
                $value[$key] = self::scrub($item);
            }
        }

        return $value;
    }
}
