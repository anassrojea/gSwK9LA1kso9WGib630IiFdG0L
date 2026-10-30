<?php

namespace App\Services\Payments\Items;

use App\Models\BundleCredit;
use App\Models\Event;
use App\Models\Order;
use App\Services\Journey\BundleCredits;
use Carbon\CarbonImmutable;

/**
 * Order line "bundle_credit": a negative line that pays the plan line with a
 * bundle credit (id = the credit). Paying spends the credit on the order's
 * event; a refund gives it back.
 */
class BundleCreditItem implements OrderItemHandler
{
    public function __construct(private BundleCredits $credits) {}

    public function fulfill(Order $order, array $line, ?Event $event, CarbonImmutable $paidAt): void
    {
        $this->credits->markUsed((string) $line['id'], $order);
    }

    public function refund(Order $order, array $line, ?Event $event): void
    {
        $this->credits->release((string) $line['id'], $order);
    }

    public function name(array $line, string $locale): ?string
    {
        $plan = BundleCredit::query()->whereKey($line['id'] ?? null)->value('plan_id');

        return trans('journey.bundles.credit_line', ['plan' => $plan ? trans("app.wizard.tiers.{$plan}", [], $locale) : ''], $locale);
    }

    public function discountable(): bool
    {
        return false;
    }
}
