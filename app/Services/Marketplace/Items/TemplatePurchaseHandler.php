<?php

namespace App\Services\Marketplace\Items;

use App\Models\DiscountCode;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Template;
use App\Services\Marketplace\DesignerLedger;
use App\Services\Marketplace\TemplateAccess;
use Carbon\CarbonImmutable;

/**
 * Order item template_purchase: one marketplace template for one event.
 *
 *   line     {type: template_purchase, id: template id, name, quantity: 1, unit_amount, amount,
 *             designer_id, prices: the template's prices per currency when bought}
 *   paid     entitlement "template:{id}" on the order's event (PublishGate accepts the template
 *            whatever its tier), and the designer's ledger credit (DesignerLedger::recordSale)
 *   refund   the entitlement ends with the order's other add-ons (OrderFulfillment), the credit is reversed
 *
 * Discount codes take money off it when they apply to every add-on (no add-on list).
 */
class TemplatePurchaseHandler implements MarketplaceItem
{
    public const TYPE = 'template_purchase';

    public function __construct(private DesignerLedger $ledger) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function fulfill(Order $order, array $line, int $index, CarbonImmutable $paidAt): void
    {
        $template = Template::query()->find($line['id']);
        $feature = TemplateAccess::feature($line['id']);

        if ($order->event_id && ! Entitlement::query()->where('source_order_id', $order->getKey())->where('feature', $feature)->exists()) {
            Entitlement::query()->create([
                'event_id' => $order->event_id,
                'feature' => $feature,
                'until' => null,
                'source_order_id' => $order->getKey(),
            ]);
        }

        if ($template) {
            $template->increment('sales_count');
            $this->ledger->recordSale($order, $index, $template);
        }
    }

    public function refund(Order $order, array $line, int $index): void
    {
        if ($this->ledger->recordRefund($order, (string) $line['id'])) {
            Template::query()->whereKey($line['id'])->where('sales_count', '>', 0)->decrement('sales_count');
        }
    }

    public function name(array $line, string $locale): ?string
    {
        $template = Template::query()->find($line['id']);
        $name = $template ? ($locale === 'ar' ? $template->name_ar : $template->name_en) : ($line['name'] ?? $line['id']);

        return trans('marketplace.items.template_purchase', ['name' => $name], $locale);
    }

    public function discountable(DiscountCode $code, string $id): bool
    {
        return $code->applies_to_addons && ($code->addon_ids === null || $code->addon_ids === []);
    }
}
