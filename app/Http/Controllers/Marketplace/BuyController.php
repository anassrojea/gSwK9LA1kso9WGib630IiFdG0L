<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Order;
use App\Models\Template;
use App\Models\User;
use App\Models\VendorListing;
use App\Services\Account\EmailVerification;
use App\Services\Marketplace\MarketplaceCheckout;
use App\Services\Marketplace\MarketplacePrices;
use App\Services\Marketplace\TemplateAccess;
use App\Services\Payments\CheckoutFailed;
use App\Services\Payments\Currencies;
use App\Services\Payments\OrderFulfillment;
use App\Services\Payments\OrderUrls;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGateways;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * One-item purchases (Inertia page marketplace/Buy), placed through the usual checkout:
 *
 *   GET|POST /buy/template/{template}?event=   a host buys a marketplace template for their event
 *   GET|POST /buy/feature                      a vendor buys "featured in {city}" for 30 days
 *   GET|POST /buy/calligraphy/{listing}        a host orders a calligraphy name logo
 *
 * A card method goes to the provider, a transfer to the order page with the bank details,
 * a zero total (a 100 % code) is paid at once.
 */
class BuyController extends Controller
{
    public function __construct(private MarketplaceCheckout $checkout, private PaymentGateways $gateways) {}

    public function template(Request $request, Template $template, TemplateAccess $access): Response
    {
        $event = $this->event($request);
        abort_unless(Template::query()->onSale()->whereKey($template->getKey())->exists(), 404);
        $locale = App::getLocale();
        $template->loadMissing('designerProfile');

        return $this->page($request, [
            'kind' => 'template',
            'title' => $locale === 'ar' ? $template->name_ar : $template->name_en,
            'subtitle' => trans('marketplace.buy.by', ['designer' => $template->designerProfile?->displayName($locale) ?? '']),
            'image' => $template->cover_path ? Storage::disk(config('filesystems.media_disk', 'public'))->url($template->cover_path) : null,
            'theme' => $template->default_config['theme'] ?? null,
            'prices' => $template->prices ?? [],
            'owned' => $access->owned($event, $template),
            'discounts' => true,
            'event' => ['id' => $event->getKey(), 'title' => $event->title],
            'endpoint' => route('buy.template.store', $template, false),
            'back' => $request->query('from') === 'wizard' ? route('create.step', [$event, 'design'], false) : route('dashboard.events.show', $event, false),
            'from' => $request->query('from') === 'wizard' ? 'wizard' : 'dashboard',
        ]);
    }

    public function buyTemplate(Request $request, Template $template): HttpResponse
    {
        EmailVerification::ensure($request, 'checkout');
        $pay = $this->pay($request, ['event_id' => ['required', 'uuid'], 'from' => ['nullable', Rule::in(['wizard', 'dashboard'])]]);
        $event = Event::query()->findOrFail($pay['event_id']);
        Gate::authorize('update', $event);

        $order = $this->checkout->buyTemplate($request->user(), $event, $template, $pay);

        return $this->start($order, $pay['from'] ?? 'dashboard');
    }

    public function feature(Request $request): Response
    {
        $listing = $this->ownListing($request->user());
        abort_unless($listing->isPublic(), 403, trans('vendors.errors.not_approved'));
        $locale = App::getLocale();

        return $this->page($request, [
            'kind' => 'feature',
            'title' => trans('vendors.feature.title'),
            'subtitle' => $listing->name($locale),
            'image' => $listing->photoUrls()[0] ?? null,
            'theme' => null,
            'prices' => config('marketplace.feature.prices'),
            'owned' => false,
            'discounts' => false,
            'event' => null,
            'cities' => collect($listing->cities ?? [])->map(fn ($c) => ['id' => $c, 'name' => trans("vendors.cities.{$c}")])->values(),
            'days' => (int) config('marketplace.feature.days', 30),
            'endpoint' => route('buy.feature.store', absolute: false),
            'back' => route('vendor-portal.show', absolute: false),
            'from' => 'dashboard',
        ]);
    }

    public function buyFeature(Request $request): HttpResponse
    {
        EmailVerification::ensure($request, 'checkout');
        $listing = $this->ownListing($request->user());
        $pay = $this->pay($request, ['city' => ['required', 'string', Rule::in(array_keys(config('marketplace.cities')))]]);

        $order = $this->checkout->buyFeature($request->user(), $listing, $pay['city'], $pay);

        return $this->start($order, 'dashboard');
    }

    public function calligraphy(Request $request, VendorListing $listing): Response
    {
        abort_unless($listing->isPublic() && $listing->offersCalligraphy(), 404);
        $event = $request->filled('event') ? $this->event($request) : null;
        $locale = App::getLocale();

        return $this->page($request, [
            'kind' => 'calligraphy',
            'title' => trans('vendors.calligraphy.title'),
            'subtitle' => $listing->name($locale),
            'image' => $listing->photoUrls()[0] ?? null,
            'theme' => null,
            'prices' => $listing->calligraphy_prices ?? [],
            'owned' => false,
            'discounts' => false,
            'event' => $event ? ['id' => $event->getKey(), 'title' => $event->title] : null,
            'events' => $request->user()->events()->latest()->limit(20)->get(['id', 'title'])->map->only('id', 'title')->values(),
            'endpoint' => route('buy.calligraphy.store', $listing, false),
            'back' => route('site.vendor'.($locale === 'en' ? '.en' : ''), $listing, false),
            'from' => 'dashboard',
        ]);
    }

    public function buyCalligraphy(Request $request, VendorListing $listing): HttpResponse
    {
        EmailVerification::ensure($request, 'checkout');
        $pay = $this->pay($request, [
            'event_id' => ['nullable', 'uuid'],
            'names' => ['required', 'string', 'max:120'],
            'brief' => ['required', 'string', 'max:2000'],
        ]);
        $event = null;
        if (! empty($pay['event_id'])) {
            $event = Event::query()->findOrFail($pay['event_id']);
            Gate::authorize('update', $event);
        }

        $order = $this->checkout->orderCalligraphy($request->user(), $event, $listing, ['names' => $pay['names'], 'brief' => $pay['brief']], $pay);

        return $this->start($order, 'dashboard');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function page(Request $request, array $item): Response
    {
        $user = $request->user();
        $locale = App::getLocale();
        $currency = $user->currency && Currencies::supported($user->currency) ? $user->currency : Currencies::forCountry($user->country);

        return Inertia::render('marketplace/Buy', [
            'item' => $item + [
                'formatted' => MarketplacePrices::formatted($item['prices'], $locale),
            ],
            'currencies' => collect(Currencies::all())->map(fn (string $c) => ['code' => $c, 'exponent' => Currencies::exponent($c)])->values(),
            'currency' => $currency,
            'country' => $user->country,
            'countries' => array_keys(config('payments.countries')),
            'fallback_currency' => Currencies::fallback(),
            'gateways' => collect($this->gateways->enabled())->map(fn (PaymentGateway $g) => ['id' => $g->id(), 'method' => $g->method(), 'currencies' => $g->currencies()])->values(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function pay(Request $request, array $rules): array
    {
        $data = $request->validate($rules + [
            'currency' => ['required', 'string', Rule::in(Currencies::all())],
            'gateway' => ['required', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:5'],
            'discount_code' => ['nullable', 'string', 'max:40'],
        ]);
        $country = strtoupper((string) ($data['country'] ?? ''));
        $data['country'] = array_key_exists($country, config('payments.countries')) ? $country : null;

        return $data;
    }

    private function start(Order $order, string $from): HttpResponse
    {
        $after = fn () => $from === 'wizard' && $order->event_id
            ? route('create.step', [$order->event_id, 'review'])
            : route('dashboard.orders.show', $order);

        if ($order->total_cents === 0) {
            app(OrderFulfillment::class)->fulfillFree($order);

            return redirect()->to($after())->with('status', trans('app.checkout.free_done', ['code' => $order->code]));
        }

        try {
            $result = $this->gateways->get($order->gateway)->start($order, $from);
        } catch (CheckoutFailed) {
            return redirect()->to(OrderUrls::back($order, $from, 'failed'))->with('warning', trans('app.checkout.errors.start_failed'));
        }

        if ($result->isRedirect()) {
            return Inertia::location($result->redirectUrl);
        }

        return redirect()->to($after())->with('status', trans('app.checkout.transfer_created', ['code' => $order->code]));
    }

    private function event(Request $request): Event
    {
        $id = (string) $request->query('event');
        abort_unless(Str::isUuid($id), 404);
        $event = Event::query()->findOrFail($id);
        Gate::authorize('update', $event);

        return $event;
    }

    private function ownListing(User $user): VendorListing
    {
        $listing = $user->vendorListing()->first();
        abort_unless($listing, 404);

        return $listing;
    }
}
