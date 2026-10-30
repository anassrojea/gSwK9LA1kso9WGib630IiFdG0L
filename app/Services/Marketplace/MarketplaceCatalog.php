<?php

namespace App\Services\Marketplace;

use App\Models\Event;
use App\Models\Template;
use App\Models\User;
use App\Services\Payments\Currencies;
use App\Services\PublishGate;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;

/**
 * Marketplace templates as the wizard shows them (design step gallery and review step):
 * the designer's name, the price in the host's currency, and whether the event owns it
 * (or gets it through Signature).
 */
class MarketplaceCatalog
{
    public function __construct(private TemplateAccess $access) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forWizard(Event $event, ?User $user): array
    {
        $locale = App::getLocale();
        $currency = $this->currency($event, $user);
        $plan = app(PublishGate::class)->currentPlan($event);

        return Template::query()->onSale()->with(['designerProfile', 'opening'])
            ->orderByDesc('sales_count')->orderByDesc('published_at')->limit(60)->get()
            ->map(fn (Template $t) => $this->card($t, $event, $currency, $plan, $locale))
            ->values()->all();
    }

    /**
     * The template the invitation uses, when it is a paid one the event doesn't have yet.
     *
     * @return array<string, mixed>|null
     */
    public function forReview(Event $event, array $config): ?array
    {
        $template = $this->access->inUse($event, $config);
        if (! $template || ! $template->isPaidMarketplace()) {
            return null;
        }

        $plan = app(PublishGate::class)->currentPlan($event);

        return $this->card($template, $event, $this->currency($event, request()->user()), $plan, App::getLocale());
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Template $t, Event $event, string $currency, string $plan, string $locale): array
    {
        $shown = isset($t->prices[$currency]) ? $currency : Currencies::fallback();
        $owned = $this->access->owned($event, $t);

        return [
            'id' => $t->id,
            'occasion' => $t->occasion,
            'name' => $locale === 'ar' ? $t->name_ar : $t->name_en,
            'designer' => $t->designerProfile?->displayName($locale),
            'designer_url' => $t->designerProfile ? MarketplaceUrl::path('designer', $locale, $t->designerProfile->slug) : null,
            'price' => isset($t->prices[$shown]) ? Currencies::format((int) $t->prices[$shown], $shown, $locale) : null,
            'owned' => $owned,
            // Signature includes it when its designer opted in: no purchase needed
            'included' => ! $owned && $this->access->covers($event, $t, $plan),
            'cover' => $t->cover_path ? Storage::disk(config('filesystems.media_disk', 'public'))->url($t->cover_path) : null,
            'opening' => $t->default_config['opening'] ?? ['id' => $t->opening_id],
            'theme' => $t->default_config['theme'] ?? null,
            'effects' => $t->default_config['effects'] ?? null,
            'buy_url' => route('buy.template', [$t, 'event' => $event->getKey(), 'from' => 'wizard'], false),
        ];
    }

    private function currency(Event $event, ?User $user): string
    {
        return $user?->currency && Currencies::supported($user->currency)
            ? $user->currency
            : Currencies::forCountry(Currencies::countryForTimezone($event->timezone));
    }
}
