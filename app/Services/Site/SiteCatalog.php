<?php

namespace App\Services\Site;

use App\Models\Addon;
use App\Models\Household;
use App\Models\Opening;
use App\Models\OpenLink;
use App\Models\Plan;
use App\Models\Template;
use App\Services\Digits;
use App\Services\Entitlements;
use App\Services\Payments\Currencies;
use App\Services\Payments\PriceList;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Collection;

/**
 * What the public site shows from the database: openings, templates per
 * occasion, plans and add-ons with their prices, and sample invitations.
 */
class SiteCatalog
{
    public function __construct(private PriceList $prices) {}

    /**
     * @return Collection<int, Opening>
     */
    public function openings(): Collection
    {
        $order = array_keys(config('marketing.opening_demos'));

        return Opening::query()->where('active', true)->get()
            ->sortBy(fn (Opening $o) => ($i = array_search($o->id, $order, true)) === false ? 99 : $i)
            ->values();
    }

    /**
     * @return Collection<int, Template>
     */
    public function templates(string $occasion): Collection
    {
        return Template::query()
            ->with(['opening', 'theme'])
            ->where('active', true)
            ->where('occasion', $occasion)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /**
     * Poster image of an opening (public/images/openings/{id}.webp), if it was generated.
     *
     * @return array{src: string, width: int, height: int}|null
     */
    public static function poster(string $openingId): ?array
    {
        $path = "images/openings/{$openingId}.webp";
        if (! is_file(public_path($path))) {
            return null;
        }

        $size = @getimagesize(public_path($path));

        return [
            'src' => asset($path).'?v='.substr(md5((string) filemtime(public_path($path))), 0, 8),
            'width' => (int) ($size[0] ?? 360),
            'height' => (int) ($size[1] ?? 640),
        ];
    }

    /**
     * Sample invitations: the demo links outside production, the configured showcase in production.
     *
     * @return list<array{url: string, name: string, occasion: string, opening: string}>
     */
    public function samples(string $locale): array
    {
        $tokens = app()->isProduction()
            ? config('marketing.showcase_tokens', [])
            : array_values(DemoSeeder::TOKENS);

        if ($tokens === []) {
            return [];
        }

        $base = rtrim((string) config('app.invite_url'), '/');
        $name = fn (?Template $t) => $t ? ($locale === 'ar' ? $t->name_ar : $t->name_en) : null;

        $households = Household::query()
            ->whereIn('token', $tokens)
            ->whereHas('event.invitation', fn ($q) => $q->whereNotNull('published_at'))
            ->with('event.invitation.template.opening')
            ->get()
            ->keyBy('token');

        $links = OpenLink::query()
            ->whereIn('token', $tokens)
            ->where('enabled', true)
            ->whereHas('event.invitation', fn ($q) => $q->whereNotNull('published_at'))
            ->with('event.invitation.template.opening')
            ->get()
            ->keyBy('token');

        $samples = [];
        foreach ($tokens as $token) {
            $owner = $households->get($token) ?? $links->get($token);
            if (! $owner) {
                continue;
            }
            $event = $owner->event;
            $template = $event->invitation?->template;
            $opening = $template?->opening;
            $samples[] = [
                'url' => "{$base}/i/{$token}",
                'name' => $name($template) ?? $event->title,
                'occasion' => (string) $event->occasion,
                'opening' => $opening ? ($locale === 'ar' ? $opening->name_ar : $opening->name_en) : '',
                'opening_id' => $opening?->id,
            ];
        }

        return $samples;
    }

    /**
     * Plans for the pricing page, with the price in a currency.
     *
     * @return list<array{id: string, amount: ?int, formatted: ?string, placeholder: bool, interval: ?string, guests: ?int, storage_days: ?int, features: list<string>}>
     */
    public function plans(string $currency, string $locale): array
    {
        return Plan::query()->where('active', true)->with('prices')->orderBy('price_cents')->get()
            ->map(function (Plan $plan) use ($currency, $locale) {
                $amount = $plan->price_cents === 0 ? 0 : $this->prices->amount($plan, $currency);
                $row = $plan->prices->firstWhere('currency', $currency);

                return [
                    'id' => $plan->id,
                    'amount' => $amount,
                    'formatted' => $amount === null ? null : ($amount === 0 ? null : Currencies::format($amount, $currency, $locale)),
                    'placeholder' => (bool) $row?->placeholder,
                    'interval' => $plan->interval,
                    'guests' => $plan->limits['guests'] ?? null,
                    'storage_days' => $plan->limits['storage_days'] ?? null,
                    'features' => array_values(array_intersect(Entitlements::FEATURES, $plan->limits['features'] ?? [])),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, amount: ?int, formatted: ?string, placeholder: bool}>
     */
    public function addons(string $currency, string $locale): array
    {
        return Addon::query()->where('active', true)->with('prices')->orderBy('price_cents')->orderBy('id')->get()
            ->map(function (Addon $addon) use ($currency, $locale) {
                $amount = $this->prices->amount($addon, $currency);

                return [
                    'id' => $addon->id,
                    'amount' => $amount,
                    'formatted' => $amount === null ? null : Currencies::format($amount, $currency, $locale),
                    'placeholder' => (bool) $addon->prices->firstWhere('currency', $currency)?->placeholder,
                ];
            })
            ->filter(fn ($addon) => $addon['amount'] !== null)
            ->values()
            ->all();
    }

    /**
     * Photo storage per plan, for the privacy policy ("Premium keeps it 30 days…").
     */
    public function retentionText(string $locale): string
    {
        return Plan::query()->where('active', true)->whereNull('interval')->orderBy('price_cents')->get()
            ->map(function (Plan $plan) use ($locale) {
                $name = trans("marketing.pricing.plans.{$plan->id}.name", [], $locale);
                $days = (int) ($plan->limits['storage_days'] ?? 0);

                return $days > 0
                    ? trans('legal.retention.days', ['plan' => $name, 'days' => Digits::localize(number_format($days), $locale)], $locale)
                    : trans('legal.retention.none', ['plan' => $name], $locale);
            })
            ->implode(trans('legal.retention.joiner', [], $locale));
    }
}
