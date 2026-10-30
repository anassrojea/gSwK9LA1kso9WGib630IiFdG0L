<?php

namespace App\Services\Org;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrgSubscription;
use App\Models\User;
use App\Services\Payments\CheckoutFailed;
use App\Services\Payments\Gateways\LemonSqueezyGateway;
use App\Services\Payments\Gateways\StripeGateway;
use App\Services\Payments\Gateways\TransferGateway;
use App\Services\Payments\PaymentGateways;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Subscription;
use LemonSqueezy\Laravel\Checkout as LemonSqueezyCheckout;
use LemonSqueezy\Laravel\LemonSqueezy;
use LemonSqueezy\Laravel\Subscription as LemonSqueezySubscription;
use Throwable;

/**
 * What the owner does on the billing page (/org/{org}/billing), per provider:
 *
 *   subscribe   stripe: Cashier Checkout in subscription mode on the owner (type "org:{org}");
 *               lemon_squeezy: the package's checkout of the plan's variant;
 *               transfer: the first period's order (OrgSubscriptions::startTransfer)
 *   change      stripe: Cashier swap (Stripe prorates); lemon_squeezy: the package's swap
 *               (Lemon Squeezy prorates); transfer: from the next period
 *   cancel      at period end everywhere; resume while the period lasts
 *   portal      Stripe's Billing Portal (card, plan, cancel), Lemon Squeezy's customer portal
 *
 * The local row changes through the providers' webhooks (OrgSubscriptions::syncStripe /
 * syncLemonSqueezy); it is also updated here right away so the page shows the change.
 */
class OrgBilling
{
    public function __construct(
        private OrgPlans $plans,
        private OrgSubscriptions $subscriptions,
        private PaymentGateways $gateways,
    ) {}

    /**
     * The payment methods offered for subscriptions, with the currencies each can charge.
     *
     * @return array<string, list<string>>
     */
    public function methods(): array
    {
        $methods = [];
        foreach ([TransferGateway::ID, StripeGateway::ID, LemonSqueezyGateway::ID] as $id) {
            $gateway = $this->gateways->find($id);
            if ($gateway?->enabled() && ($id !== LemonSqueezyGateway::ID || filled(config('org.lemon_squeezy.product_id')))) {
                $methods[$id] = $gateway->currencies();
            }
        }

        return $methods;
    }

    /**
     * Start a subscription. Returns where to send the owner.
     */
    public function subscribe(Organization $org, User $owner, string $planId, string $gatewayId, string $currency): string
    {
        if (! OrgPlans::isOrgPlan($planId)) {
            throw ValidationException::withMessages(['plan' => trans('org.billing.errors.plan')]);
        }
        if (! array_key_exists($gatewayId, $this->methods())) {
            throw ValidationException::withMessages(['gateway' => trans('app.checkout.errors.gateway')]);
        }
        if ($org->subscription()->exists()) {
            throw ValidationException::withMessages(['plan' => trans('org.billing.errors.already_subscribed')]);
        }

        $gateway = $this->gateways->get($gatewayId);
        $charge = PaymentGateways::chargeCurrency($gateway, $currency);
        if ($charge === null || $this->plans->price($planId, $charge) === null) {
            throw ValidationException::withMessages(['currency' => trans('org.billing.errors.currency')]);
        }

        return match ($gatewayId) {
            TransferGateway::ID => route('dashboard.orders.show', $this->subscriptions->startTransfer($org, $owner, $planId, $charge)),
            StripeGateway::ID => $this->stripeCheckout($org, $owner, $planId, $charge),
            LemonSqueezyGateway::ID => $this->lemonSqueezyCheckout($org, $owner, $planId),
        };
    }

    private function stripeCheckout(Organization $org, User $owner, string $planId, string $currency): string
    {
        $configured = config("org.stripe.prices.{$planId}.{$currency}");
        $interval = OrgPlans::intervalOf($planId);
        $price = filled($configured) ? $configured : [
            'price_data' => [
                'currency' => strtolower($currency),
                'product_data' => ['name' => trans('org.plans.'.OrgPlans::tierOf($planId).'.name', [], $owner->preferredLocale()).' · '.$org->name],
                'recurring' => ['interval' => $interval],
                'unit_amount' => $this->plans->price($planId, $currency),
            ],
        ];
        $metadata = ['org_id' => $org->getKey(), 'plan_id' => $planId, 'user_id' => $owner->getKey()];

        try {
            $checkout = $owner->newSubscription(OrgSubscription::stripeType($org), [$price])
                ->withMetadata($metadata)
                ->checkout([
                    'success_url' => route('org.billing', [$org, 'checkout' => 'success']),
                    'cancel_url' => route('org.billing', [$org, 'checkout' => 'cancelled']),
                    'client_reference_id' => $org->getKey(),
                    'metadata' => $metadata,
                    'locale' => 'auto',
                ]);
        } catch (Throwable $e) {
            Log::warning('Stripe subscription checkout failed', ['org' => $org->getKey(), 'error' => $e->getMessage()]);

            throw ValidationException::withMessages(['gateway' => trans('org.billing.errors.provider')]);
        }

        AuditLog::record('org_subscription.checkout', $org, ['provider' => 'stripe', 'plan' => $planId, 'currency' => $currency], $owner);

        return (string) $checkout->asStripeCheckoutSession()->url;
    }

    private function lemonSqueezyCheckout(Organization $org, User $owner, string $planId): string
    {
        $variant = config("org.lemon_squeezy.variants.{$planId}");
        if (blank($variant)) {
            throw ValidationException::withMessages(['gateway' => trans('org.billing.errors.not_configured')]);
        }

        try {
            $url = LemonSqueezyCheckout::make((string) config('lemon-squeezy.store'), (string) $variant)
                ->withName((string) $owner->name)
                ->withEmail((string) $owner->email)
                ->withCustomData([
                    'billable_id' => (string) $owner->getKey(),
                    'billable_type' => $owner->getMorphClass(),
                    'subscription_type' => OrgSubscription::stripeType($org),
                    'org_id' => $org->getKey(),
                    'plan_id' => $planId,
                    'user_id' => (string) $owner->getKey(),
                ])
                ->redirectTo(route('org.billing', [$org, 'checkout' => 'success']))
                ->url();
        } catch (Throwable $e) {
            Log::warning('Lemon Squeezy subscription checkout failed', ['org' => $org->getKey(), 'error' => $e->getMessage()]);

            throw ValidationException::withMessages(['gateway' => trans('org.billing.errors.provider')]);
        }

        AuditLog::record('org_subscription.checkout', $org, ['provider' => 'lemon_squeezy', 'plan' => $planId], $owner);

        return $url;
    }

    /**
     * Another tier or interval.
     */
    public function change(OrgSubscription $subscription, string $planId, User $actor): void
    {
        if (! OrgPlans::isOrgPlan($planId) || $planId === $subscription->plan_id && ! $subscription->next_plan_id) {
            throw ValidationException::withMessages(['plan' => trans('org.billing.errors.plan')]);
        }

        if ($subscription->isTransfer()) {
            if ($this->plans->price($planId, $subscription->currency) === null) {
                throw ValidationException::withMessages(['plan' => trans('org.billing.errors.currency')]);
            }
            $this->subscriptions->scheduleChange($subscription, $planId, $actor);

            return;
        }

        try {
            if ($subscription->provider === 'stripe') {
                $price = config("org.stripe.prices.{$planId}.{$subscription->currency}");
                if (blank($price)) {
                    throw ValidationException::withMessages(['plan' => trans('org.billing.errors.change_needs_prices')]);
                }
                $this->cashier($subscription)->swap($price);
            } else {
                $variant = config("org.lemon_squeezy.variants.{$planId}");
                if (blank($variant) || blank(config('org.lemon_squeezy.product_id'))) {
                    throw ValidationException::withMessages(['plan' => trans('org.billing.errors.not_configured')]);
                }
                $this->lemonSqueezy($subscription)->swap((string) config('org.lemon_squeezy.product_id'), (string) $variant);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Changing an org subscription failed', ['subscription' => $subscription->getKey(), 'error' => $e->getMessage()]);

            throw ValidationException::withMessages(['plan' => trans('org.billing.errors.provider')]);
        }

        $from = $subscription->plan_id;
        $subscription->update(['plan_id' => $planId, 'interval' => OrgPlans::intervalOf($planId), 'amount_cents' => $this->plans->price($planId, $subscription->currency) ?? $subscription->amount_cents]);
        AuditLog::record('org_subscription.changed', $subscription, ['from' => $from, 'to' => $planId, 'provider' => $subscription->provider], $actor);
        $this->subscriptions->applyOrgPlan($subscription->org_id);
    }

    public function cancel(OrgSubscription $subscription, User $actor): void
    {
        if ($subscription->cancel_at_period_end) {
            return;
        }

        $this->provider(fn () => $this->cancelAtProvider($subscription, now: false));
        $this->subscriptions->setCancelAtPeriodEnd($subscription, true, $actor);
    }

    public function resume(OrgSubscription $subscription, User $actor): void
    {
        if (! $subscription->cancel_at_period_end || ! $subscription->isLive()) {
            throw ValidationException::withMessages(['subscription' => trans('org.billing.errors.not_resumable')]);
        }

        $this->provider(function () use ($subscription) {
            match ($subscription->provider) {
                'stripe' => $this->cashier($subscription)->resume(),
                'lemon_squeezy' => $this->lemonSqueezy($subscription)->resume(),
                default => null,
            };
        });
        $this->subscriptions->setCancelAtPeriodEnd($subscription, false, $actor);
    }

    /**
     * Cancel at the provider: at period end, or now (admin). Transfer has nothing to call.
     */
    public function cancelAtProvider(OrgSubscription $subscription, bool $now): void
    {
        match ($subscription->provider) {
            'stripe' => $now ? $this->cashier($subscription)->cancelNow() : $this->cashier($subscription)->cancel(),
            'lemon_squeezy' => $this->lemonSqueezy($subscription)->cancel(),
            default => null,
        };
    }

    /**
     * Stripe's Billing Portal or Lemon Squeezy's customer portal, for the payer.
     */
    public function portalUrl(OrgSubscription $subscription): string
    {
        $return = route('org.billing', $subscription->org_id);

        try {
            if ($subscription->provider === 'stripe') {
                $payer = $subscription->user ?? throw new CheckoutFailed('No payer.');

                return $payer->billingPortalUrl($return, array_filter(['configuration' => config('org.stripe.portal_configuration')]));
            }

            if ($subscription->provider === 'lemon_squeezy') {
                $response = LemonSqueezy::api('GET', "subscriptions/{$subscription->provider_id}");

                return (string) ($response['data']['attributes']['urls']['customer_portal'] ?? throw new CheckoutFailed('No portal URL.'));
            }
        } catch (Throwable $e) {
            Log::warning('Billing portal failed', ['subscription' => $subscription->getKey(), 'error' => $e->getMessage()]);
        }

        throw ValidationException::withMessages(['portal' => trans('org.billing.errors.provider')]);
    }

    private function cashier(OrgSubscription $subscription): Subscription
    {
        $cashier = $subscription->user?->subscriptions()->where('stripe_id', $subscription->provider_id)->first();

        return $cashier ?? throw new CheckoutFailed('The Stripe subscription is not synced yet.');
    }

    private function lemonSqueezy(OrgSubscription $subscription): LemonSqueezySubscription
    {
        return LemonSqueezySubscription::query()->where('lemon_squeezy_id', $subscription->provider_id)->first()
            ?? throw new CheckoutFailed('The Lemon Squeezy subscription is not synced yet.');
    }

    private function provider(callable $call): void
    {
        try {
            $call();
        } catch (Throwable $e) {
            Log::warning('Org subscription provider call failed', ['error' => $e->getMessage()]);

            throw ValidationException::withMessages(['subscription' => trans('org.billing.errors.provider')]);
        }
    }
}
