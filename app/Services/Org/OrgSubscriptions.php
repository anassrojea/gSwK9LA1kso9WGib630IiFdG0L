<?php

namespace App\Services\Org;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrgSubscription;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\OrgSubscriptionNotice;
use App\Services\Payments\Currencies;
use App\Services\Payments\OrderFulfillment;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LemonSqueezy\Laravel\Subscription as LemonSqueezySubscription;

/**
 * The life of an organization's subscription (phase 8A), for every provider:
 *
 *   stripe         Checkout in subscription mode on the owner user (Cashier type "org:{org}"),
 *                  then customer.subscription.* webhooks mirror it here (syncStripe);
 *                  invoice.paid records each period's payment and invoice, invoice.payment_failed → past_due
 *   lemon_squeezy  subscription_created / updated / cancelled / expired mirror it (syncLemonSqueezy);
 *                  subscription_payment_success records each period's payment and invoice
 *   transfer       each period is an Order (DW-…, 48 h for the receipt) created 7 days before
 *                  current_period_end (createRenewalOrders); the admin's approval goes through
 *                  OrderFulfillment, which calls fulfill() here to extend the period
 *
 *   trialing | active → past_due (payment failed, or a transfer period ended unpaid)
 *                     → expired (config('org.grace_days') later, expireOverdue)
 *   cancel at period end → canceled when the period is over (or at once by the provider / an admin)
 *
 * While a subscription is live (trialing, active, past_due) organizations.plan_id is its
 * plan, so the org's events get its features (App\Services\Entitlements); otherwise it is
 * null: no white label and no new signature events, while published events keep the
 * Signature plan they were stamped with (OrgQuota). Every change is audit-logged.
 */
class OrgSubscriptions
{
    public function __construct(private OrgPlans $plans) {}

    // ---------------------------------------------------------------- shared

    /**
     * organizations.plan_id follows the live subscription (purges the org's cached pages when it changes).
     */
    public function applyOrgPlan(Organization|string $org): void
    {
        $org = $org instanceof Organization ? $org : Organization::query()->find($org);
        if (! $org) {
            return;
        }

        $live = OrgSubscription::query()->where('org_id', $org->getKey())->live()->orderByDesc('created_at')->first();
        $org->refresh();
        if ($org->plan_id !== $live?->plan_id) {
            $from = $org->plan_id;
            $org->forceFill(['plan_id' => $live?->plan_id])->save();
            AuditLog::record('org.plan_changed', $org, ['from' => $from, 'to' => $live?->plan_id, 'subscription_id' => $live?->getKey()]);
        }
    }

    public static function addInterval(CarbonImmutable $from, string $interval): CarbonImmutable
    {
        return $interval === 'year' ? $from->addYearNoOverflow() : $from->addMonthNoOverflow();
    }

    /**
     * A priced subscription line for an order (the order's items snapshot).
     *
     * @return array<string, mixed>
     */
    public function line(Organization $org, string $planId, int $amount, ?OrgSubscription $subscription = null, ?CarbonImmutable $periodEnd = null): array
    {
        return array_filter([
            'type' => 'subscription',
            'id' => $planId,
            // the snapshot's own name; pages and invoices show OrgPlans::lineName in their language
            'name' => OrgPlans::lineName($planId, 'en'),
            'quantity' => 1,
            'unit_amount' => $amount,
            'amount' => $amount,
            'org_id' => $org->getKey(),
            'subscription_id' => $subscription?->getKey(),
            // the period this payment starts from (renewals); null: from the approval
            'period_end' => $periodEnd?->toIso8601String(),
        ], fn ($v) => $v !== null);
    }

    /**
     * OrderFulfillment::complete: a paid or approved order with subscription lines.
     * Transfer orders start or extend the period; card orders (recorded from the
     * provider's invoice webhooks) only clear a past_due state.
     */
    public function fulfill(Order $order): void
    {
        foreach ($order->items ?? [] as $item) {
            if (($item['type'] ?? null) !== 'subscription') {
                continue;
            }

            $order->gateway === 'transfer'
                ? $this->fulfillTransfer($order, $item)
                : $this->providerPaid($order, $item);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function fulfillTransfer(Order $order, array $item): void
    {
        $org = Organization::query()->whereKey($item['org_id'] ?? $order->org_id)->lockForUpdate()->first();
        if (! $org || ! OrgPlans::isOrgPlan($item['id'] ?? null)) {
            AuditLog::record('org_subscription.unmatched_payment', $order, ['item' => $item]);

            return;
        }

        $planId = (string) $item['id'];
        $interval = OrgPlans::intervalOf($planId);
        $now = CarbonImmutable::now();

        $subscription = isset($item['subscription_id'])
            ? OrgSubscription::query()->whereKey($item['subscription_id'])->where('org_id', $org->getKey())->lockForUpdate()->first()
            : null;
        $live = OrgSubscription::query()->where('org_id', $org->getKey())->live()->lockForUpdate()->first();

        // a first period paid while a transfer subscription is already live counts as its renewal
        if (! $subscription && $live?->isTransfer()) {
            $subscription = $live;
        }

        if ($live && (! $subscription || ! $live->is($subscription))) {
            // the org pays another way now (or a second subscription): keep the money on record, change nothing
            AuditLog::record('org_subscription.payment_extra', $order, ['org_id' => $org->getKey(), 'live_subscription' => $live->getKey()]);

            return;
        }

        if ($subscription) {
            // renewals continue from the period end, even when paid during the grace period; late money after expiry restarts now
            $start = $subscription->isLive() && $subscription->current_period_end ? $subscription->current_period_end : $now;
            $subscription->update([
                'plan_id' => $planId,
                'interval' => $interval,
                'status' => 'active',
                'currency' => $order->currency,
                'amount_cents' => $order->total_cents,
                'current_period_start' => $start,
                'current_period_end' => static::addInterval($start, $interval),
                'next_plan_id' => null,
                'past_due_since' => null,
                'ended_at' => null,
                'canceled_at' => null,
                'cancel_at_period_end' => false,
            ]);
            AuditLog::record('org_subscription.renewed', $subscription, ['order' => $order->code, 'until' => $subscription->current_period_end->toIso8601String()]);
        } else {
            $subscription = OrgSubscription::query()->create([
                'org_id' => $org->getKey(),
                'user_id' => $order->user_id,
                'plan_id' => $planId,
                'interval' => $interval,
                'provider' => 'transfer',
                'currency' => $order->currency,
                'amount_cents' => $order->total_cents,
                'status' => 'active',
                'current_period_start' => $now,
                'current_period_end' => static::addInterval($now, $interval),
            ]);
            AuditLog::record('org_subscription.started', $subscription, ['order' => $order->code, 'provider' => 'transfer', 'plan' => $planId]);
        }

        $this->applyOrgPlan($org);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function providerPaid(Order $order, array $item): void
    {
        $subscription = isset($item['subscription_id']) ? OrgSubscription::query()->whereKey($item['subscription_id'])->lockForUpdate()->first() : null;
        if (! $subscription) {
            return;
        }

        if ($subscription->status === 'past_due' || $subscription->status === 'expired') {
            $subscription->update(['status' => 'active', 'past_due_since' => null, 'ended_at' => null]);
            AuditLog::record('org_subscription.recovered', $subscription, ['order' => $order->code]);
            $this->applyOrgPlan($subscription->org_id);
        }
    }

    /**
     * A period paid at Stripe or Lemon Squeezy: an order (method card) with one
     * subscription line, marked paid through OrderFulfillment so it gets a
     * payments row and an invoice like every other order. Idempotent on $reference.
     *
     * @param  array<string, mixed>  $raw
     */
    public function recordProviderPayment(OrgSubscription $subscription, string $provider, string $reference, int $amount, string $currency, ?CarbonImmutable $periodEnd, array $raw): string
    {
        if (Payment::query()->where('provider', $provider)->where('provider_ref', $reference)->exists()) {
            return 'duplicate: already recorded';
        }

        $order = DB::transaction(function () use ($subscription, $provider, $reference, $amount, $currency, $periodEnd) {
            $subscription = OrgSubscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();
            $existing = Order::query()->where('gateway', $provider)->where('gateway_ref', $reference)->first();
            if ($existing) {
                return $existing;
            }

            $org = $subscription->organization;
            $order = Order::query()->create([
                'user_id' => $subscription->user_id ?? $org->owner()?->getKey(),
                'org_id' => $org->getKey(),
                'items' => [$this->line($org, $subscription->plan_id, $amount, $subscription, $periodEnd)],
                'total_cents' => $amount,
                'currency' => $currency,
                'method' => 'card',
                'gateway' => $provider,
                'gateway_ref' => $reference,
                'status' => 'awaiting_payment',
            ]);
            AuditLog::record('order.created', $order, ['gateway' => $provider, 'total' => $amount, 'currency' => $currency, 'subscription_id' => $subscription->getKey()]);

            return $order;
        });

        $payment = app(OrderFulfillment::class)->markPaid($order, $provider, $reference, $amount, $currency, $raw);

        return $payment ? "recorded: {$payment->status}" : 'duplicate: already recorded';
    }

    /**
     * A payment failed or a transfer period ended unpaid: past_due, grace starts.
     */
    public function markPastDue(OrgSubscription $subscription, string $reason): bool
    {
        $changed = DB::transaction(function () use ($subscription, $reason) {
            $subscription = OrgSubscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($subscription->status, ['trialing', 'active'], true)) {
                return false;
            }

            $subscription->update(['status' => 'past_due', 'past_due_since' => now()]);
            AuditLog::record('org_subscription.past_due', $subscription, ['reason' => $reason]);

            return $subscription;
        });

        if ($changed) {
            $this->notifyOnce($changed, 'past_due', 'past_due:'.($changed->current_period_end?->toIso8601String() ?? 'none'));
        }

        return (bool) $changed;
    }

    // ---------------------------------------------------------------- transfer

    /**
     * The first transfer period: an order for the plan, 48 hours for the receipt.
     * The subscription starts when an admin approves it.
     */
    public function startTransfer(Organization $org, User $payer, string $planId, string $currency): Order
    {
        if (! OrgPlans::isOrgPlan($planId)) {
            throw ValidationException::withMessages(['plan' => trans('org.billing.errors.plan')]);
        }
        $amount = $this->plans->price($planId, $currency);
        if ($amount === null) {
            throw ValidationException::withMessages(['currency' => trans('org.billing.errors.currency')]);
        }

        return DB::transaction(function () use ($org, $payer, $planId, $currency, $amount) {
            Organization::query()->whereKey($org->getKey())->lockForUpdate()->first();
            if (OrgSubscription::query()->where('org_id', $org->getKey())->live()->exists()) {
                throw ValidationException::withMessages(['plan' => trans('org.billing.errors.already_subscribed')]);
            }

            // a first-period order not paid yet is replaced (other plan or currency)
            $pending = $this->pendingOrders($org)->filter(fn (Order $o) => ! $this->subscriptionLine($o, 'subscription_id'));
            if ($pending->contains('status', 'receipt_received')) {
                throw ValidationException::withMessages(['plan' => trans('app.checkout.errors.waiting_approval')]);
            }
            foreach ($pending as $old) {
                $old->update(['status' => 'expired']);
                AuditLog::record('order.superseded', $old, [], $payer);
            }

            return $this->transferOrder($org, $payer, $planId, $currency, $amount, null, null);
        });
    }

    /**
     * The renewal order for the subscription's coming period, once per period.
     * $again: also when the period's earlier order expired unpaid (the host asks from the billing page).
     */
    public function renewalOrder(OrgSubscription $subscription, bool $again = false): ?Order
    {
        if (! $subscription->isTransfer() || ! $subscription->isLive() || $subscription->cancel_at_period_end || ! $subscription->current_period_end) {
            return null;
        }

        return DB::transaction(function () use ($subscription, $again) {
            $subscription = OrgSubscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();
            $periodEnd = $subscription->current_period_end;
            $orders = $this->ordersForPeriod($subscription, $periodEnd);

            if ($orders->contains(fn (Order $o) => $o->isPending() || $o->isFulfilled())) {
                return null;
            }
            if ($orders->isNotEmpty() && ! $again) {
                return null;
            }

            $org = $subscription->organization;
            $planId = $subscription->next_plan_id ?? $subscription->plan_id;
            $amount = $this->plans->price($planId, $subscription->currency) ?? $subscription->amount_cents;
            $payer = $org->owner() ?? $subscription->user;

            return $payer ? $this->transferOrder($org, $payer, $planId, $subscription->currency, $amount, $subscription, $periodEnd) : null;
        });
    }

    private function transferOrder(Organization $org, User $payer, string $planId, string $currency, int $amount, ?OrgSubscription $subscription, ?CarbonImmutable $periodEnd): Order
    {
        $order = Order::query()->create([
            'user_id' => $payer->getKey(),
            'org_id' => $org->getKey(),
            'items' => [$this->line($org, $planId, $amount, $subscription, $periodEnd)],
            'total_cents' => $amount,
            'currency' => $currency,
            'method' => 'transfer',
            'gateway' => 'transfer',
            'status' => 'awaiting_payment',
            'expires_at' => now()->addHours((int) config('payments.transfer.expires_after_hours', 48)),
        ]);
        AuditLog::record('order.created', $order, array_filter([
            'gateway' => 'transfer',
            'total' => $amount,
            'currency' => $currency,
            'subscription_id' => $subscription?->getKey(),
            'renewal' => $periodEnd?->toIso8601String(),
        ]), $payer);

        return $order;
    }

    /**
     * @return Collection<int, Order>
     */
    public function ordersForPeriod(OrgSubscription $subscription, CarbonImmutable $periodEnd)
    {
        return Order::query()
            ->where('org_id', $subscription->org_id)
            ->whereJsonContains('items', [['subscription_id' => $subscription->getKey(), 'period_end' => $periodEnd->toIso8601String()]])
            ->get();
    }

    /**
     * Unpaid subscription orders of the organization (newest first).
     *
     * @return Collection<int, Order>
     */
    public function pendingOrders(Organization $org)
    {
        return Order::query()
            ->where('org_id', $org->getKey())
            ->whereNull('event_id')
            ->pending()
            ->whereJsonContains('items', [['type' => 'subscription']])
            ->latest()
            ->get();
    }

    private function subscriptionLine(Order $order, string $key): mixed
    {
        foreach ($order->items ?? [] as $item) {
            if (($item['type'] ?? null) === 'subscription') {
                return $item[$key] ?? null;
            }
        }

        return null;
    }

    /**
     * org:renewal-orders: transfer subscriptions whose period ends within config('org.transfer.renew_days_before') days.
     */
    public function createRenewalOrders(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $created = 0;

        OrgSubscription::query()
            ->where('provider', 'transfer')
            ->whereIn('status', ['active', 'past_due'])
            ->where('cancel_at_period_end', false)
            ->where('current_period_end', '<=', $now->addDays((int) config('org.transfer.renew_days_before', 7)))
            ->orderBy('current_period_end')
            ->each(function (OrgSubscription $subscription) use (&$created) {
                if ($order = $this->renewalOrder($subscription)) {
                    $created++;
                    $this->notifyOnce($subscription, 'renewal_order', 'order:'.$order->code, $order);
                }
            });

        return $created;
    }

    /**
     * org:renewal-reminders: 7 and 1 day(s) before a transfer period ends, once each.
     */
    public function sendReminders(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $days = collect(config('org.transfer.reminder_days', [7, 1]))->map(fn ($d) => (int) $d)->sort()->values();
        $sent = 0;

        OrgSubscription::query()
            ->where('provider', 'transfer')
            ->where('status', 'active')
            ->where('cancel_at_period_end', false)
            ->where('current_period_end', '>', $now)
            ->where('current_period_end', '<=', $now->addDays((int) $days->max()))
            ->each(function (OrgSubscription $subscription) use ($now, $days, &$sent) {
                $left = $now->diffInSeconds($subscription->current_period_end) / 86400;
                // the closest reminder that applies; the earlier ones are not sent late
                $due = $days->first(fn (int $d) => $left <= $d);
                if ($due === null) {
                    return;
                }
                $end = $subscription->current_period_end->toIso8601String();
                $order = $this->ordersForPeriod($subscription, $subscription->current_period_end)->first(fn (Order $o) => $o->isPending());

                if ($this->notifyOnce($subscription, 'renewal_'.$due, "{$end}:{$due}", $order)) {
                    $sent++;
                    foreach ($days->filter(fn (int $d) => $d > $due) as $skipped) {
                        $this->markSent($subscription, "{$end}:{$skipped}");
                    }
                }
            });

        return $sent;
    }

    /**
     * org:expire-subscriptions, step 1: transfer periods that ended. Unpaid → past_due;
     * cancelled at period end → canceled.
     */
    public function markOverdue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $count = 0;

        OrgSubscription::query()
            ->where('provider', 'transfer')
            ->whereIn('status', ['trialing', 'active'])
            ->where('current_period_end', '<=', $now)
            ->each(function (OrgSubscription $subscription) use (&$count) {
                if ($subscription->cancel_at_period_end) {
                    $count += $this->end($subscription, 'canceled', 'period_end') ? 1 : 0;
                } else {
                    $count += $this->markPastDue($subscription, 'transfer_period_unpaid') ? 1 : 0;
                }
            });

        return $count;
    }

    /**
     * org:expire-subscriptions, step 2: past_due longer than the grace period → expired (any provider).
     */
    public function expireOverdue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $count = 0;

        OrgSubscription::query()
            ->where('status', 'past_due')
            ->where('past_due_since', '<=', $now->subDays((int) config('org.grace_days', 7)))
            ->each(function (OrgSubscription $subscription) use (&$count) {
                if ($this->end($subscription, 'expired', 'grace_over')) {
                    $count++;
                    $this->notifyOnce($subscription->refresh(), 'expired', 'expired:'.($subscription->past_due_since?->toIso8601String() ?? ''));
                }
            });

        return $count;
    }

    /**
     * The subscription stops: canceled or expired. The org's plan goes, its
     * pending renewal orders expire.
     */
    public function end(OrgSubscription $subscription, string $status, string $reason, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($subscription, $status, $reason, $actor) {
            $subscription = OrgSubscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();
            if (! $subscription->isLive()) {
                return false;
            }

            $subscription->update([
                'status' => $status,
                'ended_at' => now(),
                'canceled_at' => $status === 'canceled' ? ($subscription->canceled_at ?? now()) : $subscription->canceled_at,
            ]);

            foreach ($this->pendingOrders($subscription->organization) as $order) {
                if ($order->isTransfer() && $order->status === 'awaiting_payment' && $this->subscriptionLine($order, 'subscription_id') === $subscription->getKey()) {
                    $order->update(['status' => 'expired']);
                    AuditLog::record('order.expired', $order, ['reason' => 'subscription_ended'], $actor);
                }
            }

            AuditLog::record("org_subscription.{$status}", $subscription, ['reason' => $reason], $actor);
            $this->applyOrgPlan($subscription->org_id);

            return true;
        });
    }

    // ---------------------------------------------------------------- local state changes

    public function setCancelAtPeriodEnd(OrgSubscription $subscription, bool $cancel, ?User $actor = null): void
    {
        $subscription->update(['cancel_at_period_end' => $cancel, 'canceled_at' => $cancel ? now() : null]);
        AuditLog::record($cancel ? 'org_subscription.cancel_scheduled' : 'org_subscription.resumed', $subscription, [], $actor);

        if ($cancel) {
            foreach ($this->pendingOrders($subscription->organization) as $order) {
                if ($order->status === 'awaiting_payment' && $this->subscriptionLine($order, 'subscription_id') === $subscription->getKey()) {
                    $order->update(['status' => 'expired']);
                    AuditLog::record('order.expired', $order, ['reason' => 'subscription_cancelled'], $actor);
                }
            }
        }
    }

    /**
     * Transfer: a tier or interval change starts with the next period's renewal order.
     */
    public function scheduleChange(OrgSubscription $subscription, string $planId, User $actor): void
    {
        $subscription->update(['next_plan_id' => $planId === $subscription->plan_id ? null : $planId]);
        AuditLog::record('org_subscription.change_scheduled', $subscription, ['from' => $subscription->plan_id, 'to' => $planId], $actor);
    }

    /**
     * Admin (Filament): add days to a transfer subscription's period (and revive it when overdue). Audit-logged.
     */
    public function adminExtend(OrgSubscription $subscription, int $days, User $admin, ?string $note = null): OrgSubscription
    {
        if (! $admin->isAdmin()) {
            throw new AuthorizationException(trans('app.payments.errors.admins_only'));
        }
        if ($days < 1 || $days > 366) {
            throw ValidationException::withMessages(['days' => trans('org.admin.errors.days')]);
        }

        return DB::transaction(function () use ($subscription, $days, $admin, $note) {
            $subscription = OrgSubscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();
            if (! $subscription->isTransfer()) {
                throw ValidationException::withMessages(['days' => trans('org.admin.errors.provider_managed')]);
            }
            if (! $subscription->isLive() && OrgSubscription::query()->where('org_id', $subscription->org_id)->live()->exists()) {
                throw ValidationException::withMessages(['days' => trans('org.billing.errors.already_subscribed')]);
            }

            $from = $subscription->current_period_end;
            $base = $from && $from->isFuture() ? $from : CarbonImmutable::now();
            $subscription->update([
                'current_period_end' => $base->addDays($days),
                'current_period_start' => $subscription->isLive() ? $subscription->current_period_start : CarbonImmutable::now(),
                'status' => 'active',
                'past_due_since' => null,
                'ended_at' => null,
            ]);

            AuditLog::record('org_subscription.extended', $subscription, array_filter([
                'days' => $days,
                'from' => $from?->toIso8601String(),
                'to' => $subscription->current_period_end->toIso8601String(),
                'note' => $note,
            ]), $admin);
            $this->applyOrgPlan($subscription->org_id);

            return $subscription;
        });
    }

    /**
     * Admin (Filament): end a subscription now, at the provider too. Audit-logged.
     */
    public function adminCancel(OrgSubscription $subscription, User $admin, string $reason): bool
    {
        if (! $admin->isAdmin()) {
            throw new AuthorizationException(trans('app.payments.errors.admins_only'));
        }

        try {
            app(OrgBilling::class)->cancelAtProvider($subscription, now: true);
        } catch (\Throwable $e) {
            Log::warning('Cancelling an org subscription at its provider failed', ['subscription' => $subscription->getKey(), 'error' => $e->getMessage()]);
            AuditLog::record('org_subscription.provider_cancel_failed', $subscription, ['error' => Str::limit($e->getMessage(), 200)], $admin);
        }

        return $this->end($subscription, 'canceled', 'admin: '.trim($reason), $admin);
    }

    // ---------------------------------------------------------------- Stripe

    public const STRIPE_STATUSES = [
        'trialing' => 'trialing', 'active' => 'active', 'past_due' => 'past_due', 'unpaid' => 'past_due',
        'paused' => 'past_due', 'canceled' => 'canceled', 'incomplete_expired' => 'expired',
    ];

    /**
     * customer.subscription.created / updated / deleted for an "org:{id}" subscription.
     *
     * @param  array<string, mixed>  $data  the Stripe subscription object
     */
    public function syncStripe(array $data): ?OrgSubscription
    {
        $orgId = $data['metadata']['org_id'] ?? null;
        $type = (string) ($data['metadata']['type'] ?? $data['metadata']['name'] ?? '');
        if (! $orgId && str_starts_with($type, 'org:')) {
            $orgId = substr($type, 4);
        }
        if (! is_string($orgId) || ! Str::isUuid($orgId) || ! Organization::query()->whereKey($orgId)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($data, $orgId) {
            $subscription = OrgSubscription::query()->where('provider', 'stripe')->where('provider_id', $data['id'])->lockForUpdate()->first();
            $item = $data['items']['data'][0] ?? [];
            $price = $item['price'] ?? [];
            $planId = $this->stripePlanFor($price['id'] ?? null) ?? ($data['metadata']['plan_id'] ?? null) ?? $subscription?->plan_id;
            $status = self::STRIPE_STATUSES[$data['status'] ?? ''] ?? null;

            if (! OrgPlans::isOrgPlan($planId) || ($status === null && ! $subscription)) {
                return $subscription;
            }

            $ts = fn ($value) => is_numeric($value) ? CarbonImmutable::createFromTimestamp((int) $value) : null;
            $periodStart = $ts($data['current_period_start'] ?? $item['current_period_start'] ?? null);
            $periodEnd = $ts($data['current_period_end'] ?? $item['current_period_end'] ?? null);
            $payer = User::query()->where('stripe_id', $data['customer'] ?? '')->first()
                ?? (isset($data['metadata']['user_id']) && Str::isUuid($data['metadata']['user_id']) ? User::query()->find($data['metadata']['user_id']) : null);

            $attributes = array_filter([
                'org_id' => $orgId,
                'user_id' => $subscription?->user_id ?? $payer?->getKey(),
                'plan_id' => $planId,
                'interval' => ($price['recurring']['interval'] ?? null) === 'year' ? 'year' : OrgPlans::intervalOf($planId),
                'provider' => 'stripe',
                'provider_id' => $data['id'],
                'provider_customer_id' => $data['customer'] ?? null,
                'provider_price' => $price['id'] ?? null,
                'currency' => isset($price['currency']) ? strtoupper($price['currency']) : ($subscription?->currency ?? 'USD'),
                'amount_cents' => isset($price['unit_amount']) ? (int) $price['unit_amount'] : $subscription?->amount_cents,
                'status' => $status ?? $subscription?->status,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
            ], fn ($v) => $v !== null);
            $attributes['cancel_at_period_end'] = (bool) ($data['cancel_at_period_end'] ?? false);

            if (in_array($attributes['status'], ['canceled', 'expired'], true)) {
                $attributes['ended_at'] = $ts($data['ended_at'] ?? null) ?? now();
                $attributes['canceled_at'] = $ts($data['canceled_at'] ?? null) ?? $subscription?->canceled_at;
            } elseif ($attributes['cancel_at_period_end']) {
                $attributes['canceled_at'] = $ts($data['canceled_at'] ?? null) ?? $subscription?->canceled_at ?? now();
            } else {
                $attributes['canceled_at'] = null;
                $attributes['ended_at'] = null;
            }
            $attributes['past_due_since'] = $attributes['status'] === 'past_due' ? ($subscription?->past_due_since ?? now()) : null;

            return $this->upsertProviderSubscription($subscription, $attributes, 'stripe:'.($data['status'] ?? '?'));
        });
    }

    /**
     * The plan row whose configured Stripe price this is.
     */
    public function stripePlanFor(?string $priceId): ?string
    {
        if (! $priceId) {
            return null;
        }

        foreach (config('org.stripe.prices', []) as $planId => $prices) {
            if (in_array($priceId, array_filter((array) $prices), true)) {
                return $planId;
            }
        }

        return null;
    }

    /**
     * invoice.paid: the period's payment, invoice and payments row.
     *
     * @param  array<string, mixed>  $invoice
     * @param  array<string, mixed>  $payload
     * @return array{0: int, 1: string} HTTP status and message
     */
    public function stripeInvoicePaid(array $invoice, array $payload): array
    {
        $subscription = $this->stripeSubscriptionForInvoice($invoice);
        if (! $subscription) {
            return $this->isOurStripeInvoice($invoice)
                ? [409, 'retry: subscription not synced yet']
                : [200, 'ignored: not an organization subscription'];
        }

        $amount = (int) ($invoice['amount_paid'] ?? 0);
        if ($amount <= 0) {
            return [200, 'ignored: nothing paid'];
        }

        $periodEnd = $invoice['lines']['data'][0]['period']['end'] ?? $invoice['period_end'] ?? null;

        return [200, $this->recordProviderPayment(
            $subscription,
            'stripe',
            (string) $invoice['id'],
            $amount,
            strtoupper((string) ($invoice['currency'] ?? $subscription->currency)),
            is_numeric($periodEnd) ? CarbonImmutable::createFromTimestamp((int) $periodEnd) : null,
            $payload,
        )];
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    public function stripeInvoiceFailed(array $invoice): string
    {
        $subscription = $this->stripeSubscriptionForInvoice($invoice);
        if (! $subscription) {
            return 'ignored: not an organization subscription';
        }

        return $this->markPastDue($subscription, 'stripe_payment_failed') ? 'past_due' : 'unchanged';
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function stripeSubscriptionForInvoice(array $invoice): ?OrgSubscription
    {
        $id = $invoice['subscription'] ?? $invoice['parent']['subscription_details']['subscription'] ?? null;
        $id = is_array($id) ? ($id['id'] ?? null) : $id;

        return $id ? OrgSubscription::query()->where('provider', 'stripe')->where('provider_id', $id)->first() : null;
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function isOurStripeInvoice(array $invoice): bool
    {
        $metadata = $invoice['parent']['subscription_details']['metadata'] ?? $invoice['subscription_details']['metadata'] ?? $invoice['lines']['data'][0]['metadata'] ?? [];

        return isset($metadata['org_id']) || str_starts_with((string) ($metadata['type'] ?? ''), 'org:');
    }

    // ---------------------------------------------------------------- Lemon Squeezy

    /**
     * subscription_created / updated / cancelled / resumed / expired / paused / unpaused.
     *
     * @param  array<string, mixed>  $payload
     */
    public function syncLemonSqueezy(array $payload): ?OrgSubscription
    {
        $custom = $payload['meta']['custom_data'] ?? [];
        $id = (string) ($payload['data']['id'] ?? '');
        $a = $payload['data']['attributes'] ?? [];

        return DB::transaction(function () use ($custom, $id, $a) {
            $subscription = OrgSubscription::query()->where('provider', 'lemon_squeezy')->where('provider_id', $id)->lockForUpdate()->first();
            $orgId = $subscription?->org_id ?? ($custom['org_id'] ?? null);
            if ($id === '' || ! is_string($orgId) || ! Str::isUuid($orgId) || ! Organization::query()->whereKey($orgId)->exists()) {
                return null;
            }

            $planId = $this->lemonPlanFor((string) ($a['variant_id'] ?? '')) ?? ($custom['plan_id'] ?? null) ?? $subscription?->plan_id;
            if (! OrgPlans::isOrgPlan($planId)) {
                return $subscription;
            }

            $date = fn ($value) => $value ? CarbonImmutable::parse($value) : null;
            $renews = $date($a['renews_at'] ?? null);
            $ends = $date($a['ends_at'] ?? null);
            $lsStatus = (string) ($a['status'] ?? '');
            $cancelled = (bool) ($a['cancelled'] ?? false) || $lsStatus === 'cancelled';
            $status = match ($lsStatus) {
                'on_trial' => 'trialing',
                'active' => 'active',
                'past_due', 'unpaid', 'paused' => 'past_due',
                // cancelled: still on until ends_at
                'cancelled' => $ends && $ends->isFuture() ? 'active' : 'canceled',
                'expired' => $cancelled ? 'canceled' : 'expired',
                default => $subscription?->status,
            };
            if ($status === null) {
                return $subscription;
            }

            $payerId = $subscription?->user_id ?? ($custom['user_id'] ?? $custom['billable_id'] ?? null);
            $payer = is_string($payerId) && Str::isUuid($payerId) ? User::query()->find($payerId) : null;
            $periodEnd = $cancelled ? ($ends ?? $renews) : ($renews ?? $ends);
            $interval = OrgPlans::intervalOf($planId);

            $attributes = array_filter([
                'org_id' => $orgId,
                'user_id' => $payer?->getKey(),
                'plan_id' => $planId,
                'interval' => $interval,
                'provider' => 'lemon_squeezy',
                'provider_id' => $id,
                'provider_customer_id' => isset($a['customer_id']) ? (string) $a['customer_id'] : null,
                'provider_price' => isset($a['variant_id']) ? (string) $a['variant_id'] : null,
                'currency' => $subscription?->currency ?? strtoupper((string) (config('payments.lemon_squeezy.currencies')[0] ?? 'USD')),
                'amount_cents' => $subscription?->amount_cents ?? ($this->plans->price($planId, 'USD') ?? 0),
                'status' => $status,
                'current_period_end' => $periodEnd,
                'current_period_start' => $periodEnd ? ($interval === 'year' ? $periodEnd->subYearNoOverflow() : $periodEnd->subMonthNoOverflow()) : null,
            ], fn ($v) => $v !== null);
            $attributes['cancel_at_period_end'] = $cancelled && $status === 'active';
            $attributes['canceled_at'] = $cancelled ? ($subscription?->canceled_at ?? now()) : null;
            $attributes['ended_at'] = in_array($status, ['canceled', 'expired'], true) ? ($ends ?? now()) : null;
            $attributes['past_due_since'] = $status === 'past_due' ? ($subscription?->past_due_since ?? now()) : null;

            if ($payer) {
                // the package's own table (UUID billables, phase 4), so its model keeps working
                LemonSqueezySubscription::query()->updateOrCreate(['lemon_squeezy_id' => $id], [
                    'billable_type' => $payer->getMorphClass(),
                    'billable_id' => $payer->getKey(),
                    'type' => OrgSubscription::stripeType($orgId),
                    'status' => $lsStatus ?: 'active',
                    'product_id' => (string) ($a['product_id'] ?? ''),
                    'variant_id' => (string) ($a['variant_id'] ?? ''),
                    'card_brand' => $a['card_brand'] ?? null,
                    'card_last_four' => $a['card_last_four'] ?? null,
                    'trial_ends_at' => $date($a['trial_ends_at'] ?? null),
                    'renews_at' => $renews,
                    'ends_at' => $ends,
                ]);
            }

            return $this->upsertProviderSubscription($subscription, $attributes, 'lemon_squeezy:'.$lsStatus);
        });
    }

    public function lemonPlanFor(string $variantId): ?string
    {
        if ($variantId === '') {
            return null;
        }

        foreach (config('org.lemon_squeezy.variants', []) as $planId => $variant) {
            if ((string) $variant === $variantId) {
                return $planId;
            }
        }

        return null;
    }

    /**
     * subscription_payment_success (a subscription-invoices object).
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: int, 1: string}
     */
    public function lemonPaymentSuccess(array $payload): array
    {
        $a = $payload['data']['attributes'] ?? [];
        $subscription = OrgSubscription::query()->where('provider', 'lemon_squeezy')->where('provider_id', (string) ($a['subscription_id'] ?? ''))->first();

        if (! $subscription) {
            return isset($payload['meta']['custom_data']['org_id'])
                ? [409, 'retry: subscription not synced yet']
                : [200, 'ignored: not an organization subscription'];
        }

        if (($a['status'] ?? 'paid') !== 'paid' || (int) ($a['total'] ?? 0) <= 0) {
            return [200, 'ignored: nothing paid'];
        }

        return [200, $this->recordProviderPayment(
            $subscription,
            'lemon_squeezy',
            'lsinv_'.($payload['data']['id'] ?? ''),
            (int) $a['total'],
            strtoupper((string) ($a['currency'] ?? $subscription->currency)),
            $subscription->current_period_end,
            $payload,
        )];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function lemonPaymentFailed(array $payload): string
    {
        $a = $payload['data']['attributes'] ?? [];
        $subscription = OrgSubscription::query()->where('provider', 'lemon_squeezy')->where('provider_id', (string) ($a['subscription_id'] ?? ''))->first();

        return $subscription && $this->markPastDue($subscription, 'lemon_squeezy_payment_failed') ? 'past_due' : 'unchanged';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertProviderSubscription(?OrgSubscription $subscription, array $attributes, string $source): OrgSubscription
    {
        $live = in_array($attributes['status'], OrgSubscription::LIVE, true);

        if ($live) {
            // one live subscription per org: a new card subscription replaces a transfer one (or an older one)
            OrgSubscription::query()
                ->where('org_id', $attributes['org_id'])
                ->live()
                ->when($subscription, fn ($q) => $q->whereKeyNot($subscription->getKey()))
                ->get()
                ->each(fn (OrgSubscription $old) => $this->end($old, 'canceled', 'replaced'));
        }

        if ($subscription) {
            $before = $subscription->only(['status', 'plan_id', 'cancel_at_period_end']);
            $subscription->update($attributes);
            $after = $subscription->only(['status', 'plan_id', 'cancel_at_period_end']);
            if ($before !== $after) {
                AuditLog::record('org_subscription.updated', $subscription, ['source' => $source, 'from' => $before, 'to' => $after]);
            }
        } else {
            $subscription = OrgSubscription::query()->create($attributes);
            AuditLog::record('org_subscription.started', $subscription, ['source' => $source, 'provider' => $subscription->provider, 'plan' => $subscription->plan_id]);
        }

        $this->applyOrgPlan($subscription->org_id);

        return $subscription;
    }

    // ---------------------------------------------------------------- notices

    /**
     * Email the org's owner once per $key (stored in reminders).
     */
    private function notifyOnce(OrgSubscription $subscription, string $kind, string $key, ?Order $order = null): bool
    {
        $sent = DB::transaction(function () use ($subscription, $key) {
            $subscription = OrgSubscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();
            if (isset(($subscription->reminders ?? [])[$key])) {
                return false;
            }
            $subscription->update(['reminders' => [...($subscription->reminders ?? []), $key => now()->toIso8601String()]]);

            return true;
        });

        if ($sent) {
            $recipient = $subscription->organization?->owner() ?? $subscription->user;
            $recipient?->notify(new OrgSubscriptionNotice($subscription, $kind, $order));
        }

        return $sent;
    }

    private function markSent(OrgSubscription $subscription, string $key): void
    {
        $subscription->refresh();
        if (! isset(($subscription->reminders ?? [])[$key])) {
            $subscription->update(['reminders' => [...($subscription->reminders ?? []), $key => 'skipped']]);
        }
    }

    /**
     * MRR per currency (subscription widget): live monthly amounts, yearly ones divided by 12.
     *
     * @return array<string, int>
     */
    public static function mrr(): array
    {
        $totals = array_fill_keys(Currencies::all(), 0);

        OrgSubscription::query()->whereIn('status', ['active', 'past_due'])->get(['currency', 'amount_cents', 'interval'])
            ->each(function (OrgSubscription $s) use (&$totals) {
                $monthly = $s->interval === 'year' ? intdiv($s->amount_cents, 12) : $s->amount_cents;
                $totals[$s->currency] = ($totals[$s->currency] ?? 0) + $monthly;
            });

        return $totals;
    }
}
