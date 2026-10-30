<?php

namespace App\Services\Org;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Organization;
use App\Models\OrgSubscription;
use App\Services\Digits;
use App\Services\Wizard\PlanAdvisor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Signature events per month (plan section 13.1: "15+ Signature events/month").
 *
 * While its subscription is live, an organization's events are Signature
 * through its plan. Publishing one uses a slot of the month: the event is
 * stamped (events.org_quota_at) and keeps the Signature plan for good
 * (events.plan_id), so it keeps what it had when the subscription ends.
 * An event that already bought Signature itself uses no slot.
 *
 * The month is counted from the subscription's period start (a yearly plan
 * gets twelve monthly windows). At the limit, creating or publishing another
 * org event is refused with a clear message; the event can still be paid for
 * on its own once the organization has no live plan.
 */
class OrgQuota
{
    public function __construct(private OrgPlans $plans) {}

    /**
     * The month window the subscription is in now.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(OrgSubscription $subscription, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $anchor = $subscription->current_period_start ?? CarbonImmutable::parse($subscription->created_at);

        if ($anchor->gt($now)) {
            return [$anchor, $anchor->addMonthNoOverflow()];
        }

        $months = (int) floor($anchor->diffInMonths($now));
        $start = $anchor->addMonthsNoOverflow($months);
        if ($start->gt($now)) {
            $start = $anchor->addMonthsNoOverflow(max(0, $months - 1));
        }

        return [$start, $start->addMonthNoOverflow()];
    }

    /**
     * @return array{limit: int, used: int, remaining: int, resets_at: ?string, active: bool}
     */
    public function summary(Organization $org): array
    {
        $subscription = $org->subscription()->first();
        $limit = $this->plans->limits($org)['signature_events_per_month'];

        if (! $subscription) {
            return ['limit' => 0, 'used' => 0, 'remaining' => 0, 'resets_at' => null, 'active' => false];
        }

        [$start, $end] = $this->window($subscription);
        $used = $this->used($org, $start, $end);

        return ['limit' => $limit, 'used' => $used, 'remaining' => max(0, $limit - $used), 'resets_at' => $end->toIso8601String(), 'active' => true];
    }

    public function used(Organization $org, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return Event::query()
            ->where('org_id', $org->getKey())
            ->where('org_quota_at', '>=', $start)
            ->where('org_quota_at', '<', $end)
            ->count();
    }

    /**
     * POST /org/{org}/events: no new client event once the month's slots are used.
     *
     * @throws ValidationException
     */
    public function ensureCanCreate(Organization $org): void
    {
        $summary = $this->summary($org);
        if ($summary['active'] && $summary['remaining'] <= 0) {
            throw ValidationException::withMessages(['quota' => $this->message($org, $summary)]);
        }
    }

    /**
     * Publishing an org event (WizardController::publish): use a slot, or refuse at the limit.
     * Called before the invitation is published; saves the event.
     *
     * @throws ValidationException
     */
    public function consume(Event $event): void
    {
        if (! $event->org_id || $event->org_quota_at !== null) {
            return;
        }

        DB::transaction(function () use ($event) {
            // one publish at a time per organization, so two can't take the last slot
            $org = Organization::query()->whereKey($event->org_id)->lockForUpdate()->first();
            $subscription = $org?->subscription()->first();
            if (! $org || ! $subscription) {
                return;
            }

            // bought Signature on its own: no slot needed
            if (PlanAdvisor::tier($event->plan_id) >= PlanAdvisor::tier('signature')) {
                return;
            }

            $summary = $this->summary($org);
            if ($summary['remaining'] <= 0) {
                throw ValidationException::withMessages(['publish' => $this->message($org, $summary)]);
            }

            $event->forceFill(['org_quota_at' => now(), 'plan_id' => 'signature'])->save();
            AuditLog::record('org.quota_used', $event, ['org_id' => $org->getKey(), 'used' => $summary['used'] + 1, 'limit' => $summary['limit']]);
        });
    }

    /**
     * @param  array{limit: int, used: int, remaining: int, resets_at: ?string, active: bool}  $summary
     */
    public function message(Organization $org, array $summary): string
    {
        $locale = app()->getLocale();
        $date = $summary['resets_at'] ? CarbonImmutable::parse($summary['resets_at'])->locale($locale)->translatedFormat('j F Y') : '';

        return trans('org.quota.reached', [
            'limit' => Digits::localize((string) $summary['limit'], $locale),
            'date' => Digits::localize($date, $locale),
        ]);
    }
}
