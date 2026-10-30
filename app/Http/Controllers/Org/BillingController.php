<?php

namespace App\Http\Controllers\Org;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrgSubscription;
use App\Services\Org\OrgBilling;
use App\Services\Org\OrgPlans;
use App\Services\Org\OrgPresenter;
use App\Services\Org\OrgSubscriptions;
use App\Services\Payments\Currencies;
use App\Services\Payments\OrderPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * /org/{org}/billing (phase 8A), the owner's: the tiers with monthly and
 * yearly prices, checkout by card (Stripe or Lemon Squeezy) or bank
 * transfer, the live subscription (change tier, cancel at period end,
 * resume, the provider's portal), and the org's orders and invoices.
 * The rules live in App\Services\Org\OrgBilling and OrgSubscriptions.
 */
class BillingController extends Controller
{
    public function __construct(
        private OrgBilling $billing,
        private OrgPlans $plans,
        private OrgPresenter $presenter,
        private OrgSubscriptions $subscriptions,
    ) {}

    public function show(Request $request, Organization $organization, OrderPresenter $orders): Response
    {
        Gate::authorize('manageBilling', $organization);
        $user = $request->user();
        $locale = app()->getLocale();

        $requested = strtoupper((string) $request->query('currency', ''));
        $currency = Currencies::supported($requested) ? $requested : ($user->currency && Currencies::supported($user->currency) ? $user->currency : Currencies::fallback());

        $subscription = $organization->subscription()->first();
        $renewal = $subscription?->isTransfer() && $subscription->current_period_end
            ? $this->subscriptions->ordersForPeriod($subscription, $subscription->current_period_end)->sortByDesc('created_at')->first()
            : null;

        return Inertia::render('org/Billing', [
            'org' => $this->presenter->present($organization, $user),
            'currency' => $currency,
            'currencies' => Currencies::all(),
            'tiers' => $this->plans->catalog($currency, $locale),
            'methods' => $this->billing->methods(),
            'renewal' => $renewal ? ['code' => $renewal->code, 'status' => $renewal->status, 'url' => route('dashboard.orders.show', $renewal, false)] : null,
            'pending' => $this->subscriptions->pendingOrders($organization)->first()?->only(['code']),
            'orders' => Order::query()
                ->where('org_id', $organization->getKey())
                ->whereNull('event_id')->where('user_id', $user->getKey())
                ->latest()
                ->limit(24)
                ->get()
                ->map(fn (Order $o) => $orders->present($o, $locale, details: false))
                ->values(),
            'checkout' => $request->query('checkout'),
        ]);
    }

    public function subscribe(Request $request, Organization $organization): HttpResponse
    {
        Gate::authorize('manageBilling', $organization);
        $data = $request->validate([
            'plan' => ['required', Rule::in(OrgPlans::planIds())],
            'gateway' => ['required', 'string', 'max:32'],
            'currency' => ['required', Rule::in(Currencies::all())],
        ]);

        $url = $this->billing->subscribe($organization, $request->user(), $data['plan'], $data['gateway'], $data['currency']);

        // a card checkout leaves the app; a transfer order opens in it
        return str_starts_with($url, url('/')) ? redirect()->to($url) : Inertia::location($url);
    }

    public function change(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manageBilling', $organization);
        $data = $request->validate(['plan' => ['required', Rule::in(OrgPlans::planIds())]]);

        $this->billing->change($this->live($organization), $data['plan'], $request->user());

        return back()->with('status', trans($this->live($organization)->isTransfer() ? 'org.billing.change_scheduled' : 'org.billing.changed'));
    }

    public function cancel(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manageBilling', $organization);
        $this->billing->cancel($this->live($organization), $request->user());

        return back()->with('status', trans('org.billing.cancelled'));
    }

    public function resume(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manageBilling', $organization);
        $this->billing->resume($this->live($organization), $request->user());

        return back()->with('status', trans('org.billing.resumed'));
    }

    /**
     * Transfer: the renewal order now (or again, when the last one expired unpaid).
     */
    public function renew(Request $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manageBilling', $organization);
        $order = $this->subscriptions->renewalOrder($this->live($organization), again: true);

        if (! $order) {
            throw ValidationException::withMessages(['renew' => trans('org.billing.errors.renewal_exists')]);
        }

        return redirect()->route('dashboard.orders.show', $order);
    }

    public function portal(Request $request, Organization $organization): HttpResponse
    {
        Gate::authorize('manageBilling', $organization);

        return Inertia::location($this->billing->portalUrl($this->live($organization)));
    }

    private function live(Organization $organization): OrgSubscription
    {
        return $organization->subscription()->first()
            ?? throw ValidationException::withMessages(['subscription' => trans('org.billing.errors.no_subscription')]);
    }
}
