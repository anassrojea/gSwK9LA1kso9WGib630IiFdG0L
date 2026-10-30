<?php

namespace App\Services\Payments;

use App\Models\Addon;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\Invoicing\Vat;
use App\Services\PublishGate;
use App\Services\Wizard\PlanAdvisor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POST /checkout: turns the host's choice (plan, add-ons, currency, method,
 * discount code) into an Order with every line priced and snapshotted, in the
 * currency the method charges (PaymentGateways::chargeCurrency), less the
 * code's discount (Discounts). Also builds what the
 * checkout UI needs (options), so the page and the server agree on prices.
 */
class CheckoutService
{
    public function __construct(
        private PaymentGateways $gateways,
        private PriceList $prices,
        private Entitlements $entitlements,
        private PublishGate $gate,
        private Discounts $discounts,
    ) {}

    /**
     * @param  list<array{id: string, quantity?: int}>  $addons
     */
    public function place(User $user, Event $event, ?string $planId, array $addons, string $currency, string $gatewayId, ?string $country = null, ?string $discountCode = null): Order
    {
        $gateway = $this->gateways->find($gatewayId);
        if (! $gateway || ! $gateway->enabled()) {
            throw ValidationException::withMessages(['gateway' => trans('app.checkout.errors.gateway')]);
        }
        if (! Currencies::supported($currency)) {
            throw ValidationException::withMessages(['currency' => trans('app.checkout.errors.currency')]);
        }

        $charge = PaymentGateways::chargeCurrency($gateway, $currency);
        if ($charge === null) {
            throw ValidationException::withMessages(['gateway' => trans('app.checkout.errors.gateway_currency')]);
        }

        $lines = $this->lines($event, $planId, $addons, $charge);

        // a code is priced in the currency actually charged (after the USD fallback)
        $code = filled($discountCode) ? $this->discounts->usable($discountCode, $user) : null;
        $discount = $code ? $this->discounts->amount($code, $lines, $charge) : 0;

        return DB::transaction(function () use ($user, $event, $gateway, $lines, $charge, $currency, $country, $code, $discount) {
            // one order at a time per event: a receipt already sent waits for the admin
            $pending = Order::query()->where('event_id', $event->getKey())->pending()->lockForUpdate()->get();
            if ($pending->contains('status', 'receipt_received')) {
                throw ValidationException::withMessages(['checkout' => trans('app.checkout.errors.waiting_approval')]);
            }
            // an unpaid order the host is replacing (other plan, method or currency)
            foreach ($pending as $old) {
                $old->update(['status' => 'expired']);
                AuditLog::record('order.superseded', $old, [], $user);
            }

            // never negative: Discounts::amount caps the discount at the lines it applies to
            $net = max(0, array_sum(array_column($lines, 'amount')) - $discount);
            // VAT-exclusive prices (config/invoicing.php): VAT is added on top here; inclusive prices: 0
            $vat = Vat::checkoutSurcharge($net, $charge, $country ?? $user->country);

            $order = Order::query()->create([
                'user_id' => $user->getKey(),
                'event_id' => $event->getKey(),
                'org_id' => $event->org_id,
                'items' => $lines,
                'total_cents' => $net + $vat,
                'discount_code_id' => $code?->getKey(),
                'discount_code' => $code?->code,
                'discount_cents' => $discount,
                'vat_cents' => $vat,
                'currency' => $charge,
                'requested_currency' => $charge === $currency ? null : $currency,
                'method' => $gateway->method(),
                'gateway' => $gateway->id(),
                'status' => 'awaiting_payment',
                'expires_at' => $gateway->method() === 'transfer'
                    ? now()->addHours((int) config('payments.transfer.expires_after_hours', 48))
                    : null,
            ]);

            $user->forceFill(array_filter(['currency' => $currency, 'country' => $country]))->save();
            AuditLog::record('order.created', $order, array_filter([
                'gateway' => $order->gateway,
                'total' => $order->total_cents,
                'currency' => $order->currency,
                'discount_code' => $order->discount_code,
                'discount' => $order->discount_cents ?: null,
            ], fn ($v) => $v !== null), $user);

            return $order;
        });
    }

    /**
     * Priced lines for the order. Every rule is checked here, not in the UI.
     *
     * @param  list<array{id: string, quantity?: int}>  $addons
     * @return list<array{type: string, id: string, name: string, quantity: int, unit_amount: int, amount: int}>
     */
    public function lines(Event $event, ?string $planId, array $addons, string $currency): array
    {
        $lines = [];
        $current = $this->gate->currentPlan($event);
        $target = $current;

        if ($planId !== null && $planId !== '') {
            $plan = $this->sellablePlans()->firstWhere('id', $planId);
            if (! $plan) {
                throw ValidationException::withMessages(['plan' => trans('app.checkout.errors.plan')]);
            }
            if (PlanAdvisor::tier($plan->id) <= PlanAdvisor::tier($current)) {
                throw ValidationException::withMessages(['plan' => trans('app.checkout.errors.plan_owned')]);
            }
            $lines[] = $this->line('plan', $plan, 1, $currency);
            $target = $plan->id;
        }

        $targetFeatures = Plan::query()->find($target)?->limits['features'] ?? [];
        $owned = $this->entitlements->features($event);
        $seen = [];

        foreach ($addons as $requested) {
            $id = (string) ($requested['id'] ?? '');
            $quantity = (int) ($requested['quantity'] ?? 1);
            $definition = config("payments.addons.{$id}");
            $addon = $definition ? Addon::query()->where('active', true)->find($id) : null;

            if (! $addon || isset($seen[$id])) {
                throw ValidationException::withMessages(['addons' => trans('app.checkout.errors.addon')]);
            }
            $seen[$id] = true;

            $stackable = (bool) ($definition['stackable'] ?? false);
            if ($quantity < 1 || $quantity > ($stackable ? (int) config('payments.max_quantity', 10) : 1)) {
                throw ValidationException::withMessages(['addons' => trans('app.checkout.errors.quantity')]);
            }
            // already part of the plan, or already bought (time-limited ones can be bought again)
            if (in_array($definition['feature'], $targetFeatures, true)
                || in_array($definition['covered_by'] ?? null, $targetFeatures, true)
                || (! $stackable && ($definition['days'] ?? null) === null && in_array($definition['feature'], $owned, true))) {
                throw ValidationException::withMessages(['addons' => trans('app.checkout.errors.addon_owned', ['name' => $this->name($addon)])]);
            }

            $lines[] = $this->line('addon', $addon, $quantity, $currency);
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['plan' => trans('app.checkout.errors.empty')]);
        }

        return $lines;
    }

    /**
     * What the checkout UI shows (wizard review step and dashboard upgrade page).
     *
     * @return array<string, mixed>
     */
    public function options(Event $event, ?User $user): array
    {
        $current = $this->gate->currentPlan($event);
        $country = $user?->country ?? Currencies::countryForTimezone($event->timezone) ?? 'other';
        $currency = $user?->currency && Currencies::supported($user->currency) ? $user->currency : Currencies::forCountry($country);
        $owned = $this->entitlements->features($event);

        return [
            'endpoint' => route('checkout.store', absolute: false),
            'discount_endpoint' => route('checkout.discount', absolute: false),
            'event_id' => $event->getKey(),
            'currencies' => collect(Currencies::all())->map(fn (string $c) => ['code' => $c, 'exponent' => Currencies::exponent($c)])->values()->all(),
            'countries' => [
                ...collect(config('payments.countries'))->map(fn (string $c, string $code) => ['code' => $code, 'currency' => $c])->values()->all(),
                ['code' => 'other', 'currency' => Currencies::fallback()],
            ],
            'country' => $country,
            'currency' => $currency,
            'fallback_currency' => Currencies::fallback(),
            'current_plan' => $current,
            'required_plan' => $this->gate->check($event)['required'],
            'plans' => $this->sellablePlans()
                ->filter(fn (Plan $p) => PlanAdvisor::tier($p->id) > PlanAdvisor::tier($current))
                ->map(fn (Plan $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'guests' => $p->limits['guests'] ?? null,
                    'features' => array_values($p->limits['features'] ?? []),
                    'prices' => $this->prices->all($p),
                ])->values()->all(),
            'current_features' => array_values(Plan::query()->find($current)?->limits['features'] ?? []),
            'addons' => Addon::query()->with('prices')->where('active', true)->orderBy('price_cents')->get()
                ->filter(fn (Addon $a) => config("payments.addons.{$a->id}") !== null)
                ->map(function (Addon $a) use ($owned) {
                    $definition = config("payments.addons.{$a->id}");

                    return [
                        'id' => $a->id,
                        'name' => $this->name($a),
                        'description' => trans()->has("app.checkout.addons.{$a->id}.text") ? trans("app.checkout.addons.{$a->id}.text") : null,
                        'feature' => $definition['feature'],
                        'covered_by' => $definition['covered_by'] ?? null,
                        'days' => $definition['days'] ?? null,
                        'stackable' => (bool) ($definition['stackable'] ?? false),
                        'max_quantity' => ($definition['stackable'] ?? false) ? (int) config('payments.max_quantity', 10) : 1,
                        'owned' => ! ($definition['stackable'] ?? false) && ($definition['days'] ?? null) === null && in_array($definition['feature'], $owned, true),
                        'prices' => $this->prices->all($a),
                    ];
                })->values()->all(),
            // VAT added on top of the prices shown (VAT-exclusive prices only): the panel adds the same line
            'vat' => Vat::pricesIncludeVat() || ! Vat::registered() ? null : [
                'rate_bp' => Vat::rateBp(),
                'currencies' => array_values((array) config('invoicing.vat.currencies', [])),
                'countries' => array_values((array) config('invoicing.vat.countries', [])),
                'label' => trans('invoices.vat_rate', ['rate' => Vat::formatRate(Vat::rateBp(), app()->getLocale())]),
            ],
            'gateways' => collect($this->gateways->enabled())
                ->map(fn (PaymentGateway $g) => ['id' => $g->id(), 'method' => $g->method(), 'currencies' => $g->currencies()])
                ->values()->all(),
        ];
    }

    /**
     * @return Collection<int, Plan>
     */
    private function sellablePlans()
    {
        return Plan::query()->with('prices')->where('active', true)->whereNull('interval')->where('id', '!=', 'free')->orderBy('price_cents')->get();
    }

    private function line(string $type, Plan|Addon $item, int $quantity, string $currency): array
    {
        $unit = $this->prices->amount($item, $currency);
        if ($unit === null) {
            throw ValidationException::withMessages(['currency' => trans('app.checkout.errors.no_price', ['currency' => $currency])]);
        }

        return [
            'type' => $type,
            'id' => $item->getKey(),
            'name' => $item->name,
            'quantity' => $quantity,
            'unit_amount' => $unit,
            'amount' => $unit * $quantity,
        ];
    }

    private function name(Addon $addon): string
    {
        $key = "app.checkout.addons.{$addon->id}.name";

        return trans()->has($key) ? trans($key) : $addon->name;
    }
}
