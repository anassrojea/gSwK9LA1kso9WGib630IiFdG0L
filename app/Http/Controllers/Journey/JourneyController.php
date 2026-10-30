<?php

namespace App\Http\Controllers\Journey;

use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\BundleCredit;
use App\Services\Account\EmailVerification;
use App\Services\Journey\BundleCheckout;
use App\Services\Journey\BundleCredits;
use App\Services\Journey\Credits;
use App\Services\Journey\Referrals;
use App\Services\Payments\CheckoutFailed;
use App\Services\Payments\Currencies;
use App\Services\Payments\OrderUrls;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGateways;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The family journey page (phase 8D, plan sections 14.2 and 14.7):
 *
 *   GET  /account/journey                 referral link, stats, credit; bundles for sale; the host's bundle credits
 *   PUT  /account/referral-footer         {enabled}: credit me on "صمّم دعوتك" of my invitations
 *   POST /bundles/{bundle}/checkout       {currency, gateway, country?}: an order for the bundle, then its gateway
 */
class JourneyController extends Controller
{
    public function show(Request $request, Referrals $referrals, Credits $credits, BundleCredits $bundles): Response
    {
        $user = $request->user();
        $locale = app()->getLocale();
        $currency = $user->currency && Currencies::supported($user->currency) ? $user->currency : Currencies::forCountry($user->country ?? 'other');

        return Inertia::render('journey/Show', [
            'referral' => [
                'enabled' => Referrals::enabled(),
                'link' => $referrals->link($user),
                'code' => $referrals->codeFor($user),
                'footer' => (bool) $user->referral_footer,
                'stats' => $referrals->stats($user),
                'credit' => (object) array_filter($credits->availableAll($user)),
                'reward' => (object) config('journey.referrals.reward'),
                'welcome_percent' => (int) config('journey.referrals.welcome_percent'),
                'cap' => (int) config('journey.referrals.monthly_cap'),
            ],
            'loyalty_percent' => config('journey.loyalty.enabled') ? (int) config('journey.loyalty.percent') : null,
            'bundles' => $bundles->forSale()->map(fn (Bundle $b) => $bundles->presentBundle($b, $locale))->values(),
            'credits' => BundleCredit::query()->with('bundle')->where('user_id', $user->getKey())->whereNull('voided_at')
                ->orderBy('used_at')->orderBy('expires_at')->get()
                ->map(fn (BundleCredit $c) => $bundles->present($c, $locale) + ['available' => $c->isAvailable()])->values(),
            'checkout' => [
                'currency' => $currency,
                'currencies' => collect(Currencies::all())->map(fn (string $c) => ['code' => $c, 'exponent' => Currencies::exponent($c)])->values(),
                'fallback_currency' => Currencies::fallback(),
                'gateways' => collect(app(PaymentGateways::class)->enabled())
                    ->map(fn (PaymentGateway $g) => ['id' => $g->id(), 'method' => $g->method(), 'currencies' => $g->currencies()])->values(),
            ],
            'urls' => [
                'footer' => route('account.referral-footer', absolute: false),
                'billing' => route('account.billing', absolute: false),
            ],
        ]);
    }

    public function footer(Request $request): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $request->user()->forceFill(['referral_footer' => (bool) $data['enabled']])->save();

        return back()->with('status', trans('journey.referrals.footer_saved'));
    }

    public function buy(Request $request, Bundle $bundle, BundleCheckout $checkout, PaymentGateways $gateways): HttpResponse
    {
        EmailVerification::ensure($request, 'checkout');

        $data = $request->validate([
            'currency' => ['required', 'string', Rule::in(Currencies::all())],
            'gateway' => ['required', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:5'],
        ]);
        $country = strtoupper((string) ($data['country'] ?? ''));

        $order = $checkout->place($request->user(), $bundle, $data['currency'], $data['gateway'], array_key_exists($country, config('payments.countries')) ? $country : null);

        try {
            $result = $gateways->get($order->gateway)->start($order, 'dashboard');
        } catch (CheckoutFailed) {
            return redirect()->to(OrderUrls::back($order, 'dashboard', 'failed'))->with('warning', trans('app.checkout.errors.start_failed'));
        }

        if ($result->isRedirect()) {
            return Inertia::location($result->redirectUrl);
        }

        return redirect()->route('dashboard.orders.show', $order)->with('status', trans('app.checkout.transfer_created', ['code' => $order->code]));
    }
}
