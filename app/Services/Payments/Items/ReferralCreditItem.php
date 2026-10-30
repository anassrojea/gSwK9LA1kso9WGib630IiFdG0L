<?php

namespace App\Services\Payments\Items;

use App\Models\Event;
use App\Models\Order;
use App\Services\Journey\Credits;
use Carbon\CarbonImmutable;

/**
 * Order line "referral_credit": a negative line paid with the host's account
 * credit (referral rewards). Paying takes it from the balance; a refund
 * restores it.
 */
class ReferralCreditItem implements OrderItemHandler
{
    public function __construct(private Credits $credits) {}

    public function fulfill(Order $order, array $line, ?Event $event, CarbonImmutable $paidAt): void
    {
        $this->credits->spend($order, abs((int) $line['amount']));
    }

    public function refund(Order $order, array $line, ?Event $event): void
    {
        $this->credits->restore($order, abs((int) $line['amount']));
    }

    public function name(array $line, string $locale): ?string
    {
        return trans('journey.referrals.credit_line', [], $locale);
    }

    public function discountable(): bool
    {
        return false;
    }
}
