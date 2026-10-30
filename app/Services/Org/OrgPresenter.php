<?php

namespace App\Services\Org;

use App\Models\Organization;
use App\Models\OrgMember;
use App\Models\OrgSubscription;
use App\Models\User;

/**
 * The organization as its pages see it (Inertia prop "org", shared by every
 * /org/{org} page and its layout): profile, the viewer's role, branding,
 * the live subscription and this month's signature-event quota.
 */
class OrgPresenter
{
    public function __construct(
        private OrgPlans $plans,
        private OrgQuota $quota,
        private WhiteLabel $whiteLabel,
        private OrgTeam $team,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Organization $org, User $viewer): array
    {
        $role = $viewer->roleIn($org) ?? ($viewer->isAdmin() ? OrgMember::ROLE_OWNER : null);
        $subscription = $org->subscription()->first();
        $limits = $this->plans->limits($org);

        return [
            'id' => $org->id,
            'name' => $org->name,
            'type' => $org->type,
            'city' => $org->city,
            'contact_phone' => $org->contact_phone,
            'contact_email' => $org->contact_email,
            'logo_url' => $org->logo_url,
            'role' => $role,
            'can' => [
                'manage' => $viewer->can('update', $org),
                'create_events' => $viewer->can('createEvents', $org),
                'billing' => $viewer->can('manageBilling', $org),
            ],
            'white_label' => [
                'enabled' => $this->whiteLabel->enabled($org),
                'brand_color' => $this->whiteLabel->brandColor($org),
                'sender_name' => $org->white_label['sender_name'] ?? null,
                'raw_brand_color' => $org->white_label['brand_color'] ?? null,
            ],
            'plan' => $org->plan_id,
            'tier' => $org->plan_id && OrgPlans::isOrgPlan($org->plan_id) ? OrgPlans::tierOf($org->plan_id) : null,
            'limits' => $limits,
            'subscription' => $subscription ? $this->subscription($subscription) : null,
            'quota' => $this->quota->summary($org),
            'seats' => ['used' => $this->team->seatsUsed($org), 'limit' => $limits['team_seats']],
            'urls' => [
                'dashboard' => route('org.show', $org, false),
                'team' => route('org.team', $org, false),
                'billing' => route('org.billing', $org, false),
                'settings' => route('org.settings', $org, false),
                'halls' => route('dashboard.halls.index', absolute: false),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function subscription(OrgSubscription $s): array
    {
        return [
            'id' => $s->id,
            'status' => $s->status,
            'plan_id' => $s->plan_id,
            'tier' => OrgPlans::tierOf($s->plan_id),
            'interval' => $s->interval,
            'provider' => $s->provider,
            'currency' => $s->currency,
            'amount' => $s->amount_cents,
            'current_period_end' => $s->current_period_end?->toIso8601String(),
            'cancel_at_period_end' => $s->cancel_at_period_end,
            'next_plan_id' => $s->next_plan_id,
            'past_due_since' => $s->past_due_since?->toIso8601String(),
            'grace_until' => $s->past_due_since?->addDays((int) config('org.grace_days', 7))->toIso8601String(),
        ];
    }
}
