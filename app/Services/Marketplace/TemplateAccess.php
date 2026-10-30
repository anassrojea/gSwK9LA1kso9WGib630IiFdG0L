<?php

namespace App\Services\Marketplace;

use App\Models\Entitlement;
use App\Models\Event;
use App\Models\MarketplaceSignatureUse;
use App\Models\Template;
use App\Models\Theme;
use App\Services\Wizard\PlanAdvisor;

/**
 * Which marketplace template an invitation uses, and whether the event may publish it
 * (PlanAdvisor adds the reason "template", PublishGate asks covers()):
 *
 *   in use    the config's theme is a designer's theme (designer themes are only sold
 *             through their templates): the invitation's template_id when it matches,
 *             else the designer's template with that theme
 *   covered   the event holds the entitlement "template:{id}" (a template_purchase order,
 *             OrderFulfillment → TemplatePurchaseHandler), whatever the template's tier;
 *             or the event is on Signature and the designer included the template in
 *             Signature (that use is recorded for the monthly pool, never charged)
 *
 * A template's own opening and theme are covered by the same entitlement, so a Premium
 * opening inside a bought template needs no Premium plan.
 */
class TemplateAccess
{
    public const PREFIX = 'template:';

    public static function feature(Template|string $template): string
    {
        return self::PREFIX.($template instanceof Template ? $template->getKey() : $template);
    }

    public function inUse(Event $event, array $config): ?Template
    {
        $themeId = $config['theme']['id'] ?? null;
        if (! is_string($themeId)) {
            return null;
        }

        $designerTheme = Theme::query()->whereKey($themeId)->whereNotNull('designer_id')->exists();
        if (! $designerTheme) {
            return null;
        }

        $templateId = $event->invitation?->template_id;
        $candidates = Template::query()->marketplace()->where('theme_id', $themeId);

        return ($templateId ? (clone $candidates)->whereKey($templateId)->first() : null)
            ?? $candidates->orderByDesc('published_at')->first();
    }

    /** The event bought this template (an entitlement that hasn't ended). */
    public function owned(Event $event, Template|string $template): bool
    {
        if (! $event->exists) {
            return false;
        }

        return Entitlement::query()
            ->where('event_id', $event->getKey())
            ->where('feature', static::feature($template))
            ->where(fn ($q) => $q->whereNull('until')->orWhere('until', '>', now()))
            ->exists();
    }

    /**
     * May the event publish with this template? (A free marketplace template, with no price, always.)
     */
    public function covers(Event $event, Template $template, string $plan): bool
    {
        if (! $template->isPaidMarketplace() || $this->owned($event, $template)) {
            return true;
        }

        return $template->included_in_signature && PlanAdvisor::tier($plan) >= PlanAdvisor::tier('signature');
    }

    /** Is the opening or theme $reason part of the template the event owns? */
    public function coversDesign(Event $event, array $config, string $reason): bool
    {
        $template = $this->inUse($event, $config);
        if (! $template || ! $this->owned($event, $template)) {
            return false;
        }

        return match ($reason) {
            'opening' => ($config['opening']['id'] ?? null) === $template->opening_id,
            'theme' => ($config['theme']['id'] ?? null) === $template->theme_id,
            default => false,
        };
    }

    /**
     * A Signature event published with an "included in Signature" template it didn't buy:
     * one row per template and event, for the monthly pool (docs/MARKETPLACE.md).
     */
    public function recordSignatureUse(Event $event, array $config, string $plan): ?MarketplaceSignatureUse
    {
        $template = $this->inUse($event, $config);
        if (! $template || ! $template->isPaidMarketplace() || ! $template->included_in_signature
            || PlanAdvisor::tier($plan) < PlanAdvisor::tier('signature') || $this->owned($event, $template)) {
            return null;
        }

        return MarketplaceSignatureUse::query()->firstOrCreate(
            ['template_id' => $template->getKey(), 'event_id' => $event->getKey()],
            ['designer_id' => $template->designer_id, 'plan_id' => $plan, 'period' => now()->format('Y-m')],
        );
    }
}
