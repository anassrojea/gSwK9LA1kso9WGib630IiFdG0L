<?php

namespace App\Services\Payments;

use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\TransferReceipt;
use App\Models\User;
use App\Notifications\OrderApproved;
use App\Notifications\OrderExpired;
use App\Notifications\OrderRejected;
use App\Services\Wizard\PlanAdvisor;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every order state change after checkout, for every method, so paying
 * always unlocks the same way (plan section 12):
 *
 *   card:     awaiting_payment → paid (webhook)                    → refunded
 *   transfer: awaiting_payment → receipt_received → approved (admin) → refunded
 *                                                 → rejected (admin)
 *             awaiting_payment → expired (48h, orders:expire)
 *
 * paid / approved grant the order's entitlements to its event: the plan
 * (events.plan_id), add-ons (entitlements rows, with `until` when time-limited)
 * and extra guest packs (events.guest_limit). Each change is audit-logged.
 */
class OrderFulfillment
{
    /**
     * Money arrived from a card gateway (webhook). Idempotent: a provider
     * reference is recorded once, so retried webhooks change nothing. An
     * amount or currency that doesn't match the order is recorded as
     * "mismatch" and unlocks nothing.
     *
     * @param  array<string, mixed>  $raw
     */
    public function markPaid(Order $order, string $provider, string $reference, int $amount, string $currency, array $raw = []): ?Payment
    {
        $payment = DB::transaction(function () use ($order, $provider, $reference, $amount, $currency, $raw) {
            $order = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (Payment::query()->where('provider', $provider)->where('provider_ref', $reference)->exists()) {
                return null;
            }

            $matches = $amount === $order->total_cents && $currency === $order->currency;

            // concurrent retries wait on the order's row lock above, so this can't race
            $payment = $order->payments()->create([
                'provider' => $provider,
                'provider_ref' => $reference,
                'amount_cents' => $amount,
                'currency' => $currency,
                'status' => $matches ? 'succeeded' : 'mismatch',
                'raw' => $raw,
            ]);

            if (! $matches) {
                AuditLog::record('order.payment_mismatch', $order, ['provider' => $provider, 'reference' => $reference, 'amount' => $amount, 'currency' => $currency]);

                return $payment;
            }

            // a second payment for a paid order, or money after a refund: kept for the admin, nothing more is unlocked
            if (in_array($order->status, ['paid', 'approved', 'refunded'], true)) {
                AuditLog::record('order.payment_extra', $order, ['provider' => $provider, 'reference' => $reference, 'status' => $order->status]);

                return $payment;
            }

            $this->complete($order, 'paid', null, ['provider' => $provider, 'reference' => $reference]);

            return $payment;
        });

        return $payment;
    }

    /**
     * The customer says the receipt is on its way over WhatsApp (POST /orders/{code}/receipt).
     */
    public function receiptSent(Order $order, User $customer, ?string $note = null): TransferReceipt
    {
        return DB::transaction(function () use ($order, $customer, $note) {
            $order = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($order->status === 'receipt_received') {
                return $order->latestReceipt()->firstOrFail();
            }

            if (! $order->isTransfer() || $order->status !== 'awaiting_payment') {
                throw ValidationException::withMessages(['receipt' => trans('app.payments.errors.receipt_closed')]);
            }

            $receipt = $order->transferReceipts()->create([
                'received_via' => 'whatsapp',
                'received_at' => now(),
                'note' => $note,
            ]);
            $order->update(['status' => 'receipt_received']);
            AuditLog::record('order.receipt_received', $order, ['receipt_id' => $receipt->id], $customer);

            return $receipt;
        });
    }

    /**
     * An admin saw the money in the bank account. $bankReference is the bank's
     * own reference for the transfer: unique across receipts, so one transfer
     * can't approve two orders.
     */
    public function approveTransfer(Order $order, User $admin, string $bankReference, ?string $note = null): void
    {
        $this->ensureAdmin($admin);
        $bankReference = trim($bankReference);

        if ($bankReference === '') {
            throw ValidationException::withMessages(['bank_reference' => trans('validation.required', ['attribute' => 'bank reference'])]);
        }

        try {
            DB::transaction(function () use ($order, $admin, $bankReference, $note) {
                $order = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

                // late money for an expired order can still be approved
                if (! $order->isTransfer() || ! in_array($order->status, [...Order::PENDING, 'expired'], true)) {
                    throw ValidationException::withMessages(['status' => trans('app.payments.errors.not_approvable')]);
                }

                if (TransferReceipt::query()->where('bank_reference', $bankReference)->exists()) {
                    throw ValidationException::withMessages(['bank_reference' => trans('app.payments.errors.duplicate_reference')]);
                }

                $receipt = $order->latestReceipt()->first() ?? $order->transferReceipts()->create([
                    'received_via' => 'admin',
                    'received_at' => now(),
                ]);
                $receipt->update([
                    'approved_by' => $admin->getKey(),
                    'decided_at' => now(),
                    'decision' => 'approved',
                    'bank_reference' => $bankReference,
                    'note' => $note ?? $receipt->note,
                ]);

                $order->payments()->create([
                    'provider' => 'transfer',
                    'provider_ref' => $bankReference,
                    'amount_cents' => $order->total_cents,
                    'currency' => $order->currency,
                    'status' => 'succeeded',
                    'raw' => ['approved_by' => $admin->getKey(), 'receipt_id' => $receipt->id],
                ]);

                $this->complete($order, 'approved', $admin, ['bank_reference' => $bankReference, 'receipt_id' => $receipt->id]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['bank_reference' => trans('app.payments.errors.duplicate_reference')]);
        }
    }

    public function rejectTransfer(Order $order, User $admin, string $reason): void
    {
        $this->ensureAdmin($admin);
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => trans('validation.required', ['attribute' => 'reason'])]);
        }

        DB::transaction(function () use ($order, $admin, $reason) {
            $order = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $order->isTransfer() || ! in_array($order->status, [...Order::PENDING, 'expired'], true)) {
                throw ValidationException::withMessages(['status' => trans('app.payments.errors.not_rejectable')]);
            }

            $order->latestReceipt()->first()?->update([
                'approved_by' => $admin->getKey(),
                'decided_at' => now(),
                'decision' => 'rejected',
                'reason' => $reason,
            ]);

            $order->update(['status' => 'rejected', 'rejection_reason' => $reason]);
            AuditLog::record('order.rejected', $order, ['reason' => $reason], $admin);

            DB::afterCommit(fn () => $order->user?->notify(new OrderRejected($order)));
        });
    }

    /**
     * A paid or approved order was refunded (by an admin in Filament, or
     * reported by the gateway): its add-ons end now, and the event drops back
     * to the best plan it still has from other orders.
     */
    public function refund(Order $order, ?User $actor = null, ?string $reason = null): bool
    {
        if ($actor) {
            $this->ensureAdmin($actor);
        }

        return DB::transaction(function () use ($order, $actor, $reason) {
            $order = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $order->isFulfilled()) {
                return false;
            }

            $order->update(['status' => 'refunded', 'refunded_at' => now()]);

            Entitlement::query()
                ->where('source_order_id', $order->getKey())
                ->where(fn ($q) => $q->whereNull('until')->orWhere('until', '>', now()))
                ->update(['until' => now()]);

            if ($event = $order->event_id ? Event::query()->whereKey($order->event_id)->lockForUpdate()->first() : null) {
                if ($order->planId() !== null && $event->plan_id === $order->planId()) {
                    $event->plan_id = $this->bestPlanFromOrders($event, except: $order);
                }
                $this->syncGuestLimit($event);
                $event->save();
            }

            AuditLog::record('order.refunded', $order, array_filter(['reason' => $reason]), $actor);

            return true;
        });
    }

    /**
     * 48 hours without a receipt (orders:expire). Orders with a receipt wait for the admin instead.
     */
    public function expire(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $order = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $order->isTransfer() || $order->status !== 'awaiting_payment') {
                return false;
            }

            $order->update(['status' => 'expired']);
            AuditLog::record('order.expired', $order, ['expires_at' => $order->expires_at?->toIso8601String()]);

            DB::afterCommit(fn () => $order->user?->notify(new OrderExpired($order)));

            return true;
        });
    }

    /**
     * Highest plan tier the event bought through orders still paid or approved.
     */
    public function bestPlanFromOrders(Event $event, ?Order $except = null): ?string
    {
        return Order::query()
            ->where('event_id', $event->getKey())
            ->fulfilled()
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->get()
            ->map(fn (Order $o) => $o->planId())
            ->filter()
            ->sortByDesc(fn (string $plan) => PlanAdvisor::tier($plan))
            ->first();
    }

    /**
     * events.guest_limit: the plan's guests plus every active extra guest pack,
     * or null (the plan's own limit applies) when the event has no packs.
     */
    public function syncGuestLimit(Event $event): void
    {
        $extra = 0;
        foreach (config('payments.addons') as $definition) {
            if (! isset($definition['guests'])) {
                continue;
            }
            $packs = Entitlement::query()
                ->where('event_id', $event->getKey())
                ->where('feature', $definition['feature'])
                ->where(fn ($q) => $q->whereNull('until')->orWhere('until', '>', now()))
                ->count();
            $extra += $packs * (int) $definition['guests'];
        }

        if ($extra === 0) {
            $event->guest_limit = null;

            return;
        }

        $planId = $event->plan_id ?? $event->organization?->plan_id ?? 'free';
        $base = (int) (Plan::query()->find($planId)?->limits['guests'] ?? 0);
        $event->guest_limit = $base + $extra;
    }

    /**
     * paid / approved: set the status and grant what the order bought.
     *
     * @param  array<string, mixed>  $data
     */
    private function complete(Order $order, string $status, ?User $actor, array $data): void
    {
        $paidAt = CarbonImmutable::now();
        $order->update(['status' => $status, 'paid_at' => $paidAt]);

        $this->grant($order, $paidAt);
        AuditLog::record("order.{$status}", $order, $data + ['total' => $order->total_cents, 'currency' => $order->currency], $actor);

        DB::afterCommit(fn () => $order->user?->notify(new OrderApproved($order)));
    }

    private function grant(Order $order, CarbonImmutable $from): void
    {
        $event = $order->event_id ? Event::query()->whereKey($order->event_id)->lockForUpdate()->first() : null;
        if (! $event) {
            return;
        }

        $plan = $order->planId();
        if ($plan !== null && PlanAdvisor::tier($plan) > PlanAdvisor::tier($event->plan_id)) {
            $event->plan_id = $plan;
        }

        foreach ($order->addonLines() as $line) {
            $definition = config("payments.addons.{$line['id']}");
            if (! $definition) {
                continue;
            }
            $until = isset($definition['days']) ? $from->addDays((int) $definition['days']) : null;

            for ($i = 0; $i < max(1, $line['quantity']); $i++) {
                Entitlement::query()->create([
                    'event_id' => $event->getKey(),
                    'feature' => $definition['feature'],
                    'until' => $until,
                    'source_order_id' => $order->getKey(),
                ]);
            }

            // photo cloud keeps the album: the event's storage lasts at least as long
            if ($definition['feature'] === 'photo_cloud' && $until && (! $event->storage_until || $event->storage_until->lt($until))) {
                $event->storage_until = $until->toDateString();
            }
        }

        $this->syncGuestLimit($event);
        $event->save();
    }

    private function ensureAdmin(User $user): void
    {
        if (! $user->isAdmin()) {
            throw new AuthorizationException(trans('app.payments.errors.admins_only'));
        }
    }
}
