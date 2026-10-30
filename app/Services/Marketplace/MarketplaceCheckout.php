<?php

namespace App\Services\Marketplace;

use App\Models\CalligraphyRequest;
use App\Models\Event;
use App\Models\Order;
use App\Models\Template;
use App\Models\User;
use App\Models\VendorListing;
use App\Services\Marketplace\Items\CalligraphyRequestHandler;
use App\Services\Marketplace\Items\TemplatePurchaseHandler;
use App\Services\Marketplace\Items\VendorFeatureHandler;
use App\Services\Payments\CheckoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Prices the marketplace's order items and places them through the existing checkout
 * (CheckoutService::placeLines: the same gateways, transfer approval, discounts, VAT and
 * invoices). Fulfilment is the item handlers' job (Items\*Handler).
 */
class MarketplaceCheckout
{
    public function __construct(private CheckoutService $checkout, private TemplateAccess $access) {}

    /**
     * @param  array{currency: string, gateway: string, country?: ?string, discount_code?: ?string}  $pay
     */
    public function buyTemplate(User $user, Event $event, Template $template, array $pay): Order
    {
        return $this->checkout->placeLines(
            $user,
            $event,
            fn (string $currency) => [$this->templateLine($event, $template, $currency)],
            $pay['currency'],
            $pay['gateway'],
            $pay['country'] ?? null,
            $pay['discount_code'] ?? null,
        );
    }

    /**
     * @param  array{currency: string, gateway: string, country?: ?string, discount_code?: ?string}  $pay
     */
    public function buyFeature(User $user, VendorListing $listing, string $city, array $pay): Order
    {
        return $this->checkout->placeLines(
            $user,
            null,
            fn (string $currency) => [$this->featureLine($listing, $city, $currency)],
            $pay['currency'],
            $pay['gateway'],
            $pay['country'] ?? null,
            $pay['discount_code'] ?? null,
        );
    }

    /**
     * The host's brief is saved first (awaiting_payment), then its order; paying moves it to "new".
     *
     * @param  array{names: string, brief: string}  $brief
     * @param  array{currency: string, gateway: string, country?: ?string, discount_code?: ?string}  $pay
     */
    public function orderCalligraphy(User $user, ?Event $event, VendorListing $listing, array $brief, array $pay): Order
    {
        return DB::transaction(function () use ($user, $event, $listing, $brief, $pay) {
            $request = CalligraphyRequest::query()->create([
                'listing_id' => $listing->getKey(),
                'user_id' => $user->getKey(),
                'event_id' => $event?->getKey(),
                'names' => $brief['names'],
                'brief' => $brief['brief'],
                'status' => 'awaiting_payment',
            ]);

            // not the event's order: it must not replace the host's own unpaid plan order for the event
            $order = $this->checkout->placeLines(
                $user,
                null,
                fn (string $currency) => [$this->calligraphyLine($listing, $request, $currency)],
                $pay['currency'],
                $pay['gateway'],
                $pay['country'] ?? null,
                $pay['discount_code'] ?? null,
            );
            $request->update(['order_id' => $order->getKey()]);

            return $order;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function templateLine(Event $event, Template $template, string $currency): array
    {
        $onSale = Template::query()->onSale()->whereKey($template->getKey())->exists();
        if (! $onSale || ! $template->isPaidMarketplace()) {
            throw ValidationException::withMessages(['template' => trans('marketplace.errors.not_on_sale')]);
        }
        if ($this->access->owned($event, $template)) {
            throw ValidationException::withMessages(['template' => trans('marketplace.errors.owned')]);
        }

        return $this->line(TemplatePurchaseHandler::TYPE, $template->getKey(), $template->name_en, $template->prices[$currency] ?? null, $currency, [
            'designer_id' => $template->designer_id,
            'prices' => $template->prices,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function featureLine(VendorListing $listing, string $city, string $currency): array
    {
        if (! $listing->isPublic()) {
            throw ValidationException::withMessages(['listing' => trans('vendors.errors.not_approved')]);
        }
        if (! in_array($city, $listing->cities ?? [], true)) {
            throw ValidationException::withMessages(['city' => trans('vendors.errors.city')]);
        }

        return $this->line(VendorFeatureHandler::TYPE, $listing->getKey(), $listing->name_en, config("marketplace.feature.prices.{$currency}"), $currency, [
            'city' => $city,
            'category' => $listing->category,
            'days' => (int) config('marketplace.feature.days', 30),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function calligraphyLine(VendorListing $listing, CalligraphyRequest $request, string $currency): array
    {
        if (! $listing->isPublic() || ! $listing->offersCalligraphy()) {
            throw ValidationException::withMessages(['listing' => trans('vendors.errors.no_calligraphy')]);
        }

        return $this->line(CalligraphyRequestHandler::TYPE, $listing->getKey(), $listing->name_en, $listing->calligraphy_prices[$currency] ?? null, $currency, [
            'request_id' => $request->getKey(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function line(string $type, string $id, string $name, mixed $amount, string $currency, array $extra): array
    {
        if ($amount === null || (int) $amount <= 0) {
            throw ValidationException::withMessages(['currency' => trans('app.checkout.errors.no_price', ['currency' => $currency])]);
        }

        return [
            'type' => $type,
            'id' => $id,
            'name' => $name,
            'quantity' => 1,
            'unit_amount' => (int) $amount,
            'amount' => (int) $amount,
        ] + $extra;
    }
}
