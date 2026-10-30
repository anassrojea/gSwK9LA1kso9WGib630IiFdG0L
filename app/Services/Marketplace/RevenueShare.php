<?php

namespace App\Services\Marketplace;

use App\Models\Invoice;
use App\Models\Order;
use App\Services\Invoicing\Vat;
use App\Services\Payments\Discounts;

/**
 * The designer's share of one order line, in integer minor units of the order's currency
 * (docs/MARKETPLACE.md, "Revenue share"):
 *
 *   1. the line's net (VAT excluded) with 6B's rules: Invoicer's per-line VAT split (the
 *      order's invoice when it exists; the ledger is posted in OrderFulfillment::complete()
 *      right after grant(), before the invoice is numbered, so the same Vat:: math on the order);
 *   2. the order's discount line (negative, also net of VAT) is shared among the lines the
 *      code covered (Discounts::covers), in proportion to their amounts, largest remainder
 *      first so the shares add up exactly;
 *   3. net after discount × share_bp / 10000, rounded half away from zero (Vat::divideRounded);
 *      the platform keeps the rest.
 *
 * e.g. SAR 115.00 VAT-inclusive at 15 %, a 20 % code: total 92.00, VAT 12.00, line net 100.00,
 * discount net −20.00, net 80.00, designer 70 % = 56.00, platform 24.00.
 */
class RevenueShare
{
    public function __construct(private Discounts $discounts) {}

    /**
     * @return array{net: int, share: int, platform: int, rate_bp: int, line_net: int, discount_net: int}
     */
    public function split(Order $order, int $index, int $rateBp): array
    {
        $net = $this->netAfterDiscount($order, $index, $lineNet, $discountNet);
        $share = $net > 0 ? Vat::divideRounded($net * $rateBp, 10000) : 0;

        return [
            'net' => max(0, $net),
            'share' => $share,
            'platform' => max(0, $net) - $share,
            'rate_bp' => $rateBp,
            'line_net' => $lineNet,
            'discount_net' => $discountNet,
        ];
    }

    /**
     * The line's net amount after its part of the discount, VAT excluded.
     */
    public function netAfterDiscount(Order $order, int $index, ?int &$lineNet = null, ?int &$discountNet = null): int
    {
        $items = array_values($order->items ?? []);
        $invoice = Invoice::query()->where('order_id', $order->getKey())->where('kind', Invoice::KIND_INVOICE)->first();

        [$nets, $discountTotal] = $invoice ? $this->fromInvoice($invoice, count($items)) : $this->withoutInvoice($order, $items);

        $lineNet = $nets[$index] ?? 0;
        $discountNet = $this->discountShare($order, $items, $nets, $discountTotal)[$index] ?? 0;

        return $lineNet + $discountNet;
    }

    /**
     * Largest-remainder split of $total (≤ 0) over the lines the code covered, by amount.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<int>  $nets
     * @return array<int, int>
     */
    private function discountShare(Order $order, array $items, array $nets, int $total): array
    {
        if ($total === 0) {
            return [];
        }

        $code = $order->discountCode;
        $weights = [];
        foreach ($items as $i => $item) {
            $covered = $code ? $this->discounts->covers($code, (string) $item['type'], (string) $item['id']) : true;
            if ($covered && (int) $item['amount'] > 0) {
                $weights[$i] = (int) $item['amount'];
            }
        }

        return static::allocate($total, $weights);
    }

    /**
     * Split an integer amount by weights so the parts add up exactly (largest remainder,
     * ties to the earlier line). Works for negative amounts too.
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    public static function allocate(int $amount, array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum <= 0) {
            return [];
        }

        $sign = $amount < 0 ? -1 : 1;
        $abs = abs($amount);
        $parts = [];
        $remainders = [];
        foreach ($weights as $i => $weight) {
            $parts[$i] = intdiv($abs * $weight, $sum);
            $remainders[$i] = ($abs * $weight) % $sum;
        }

        $left = $abs - array_sum($parts);
        arsort($remainders, SORT_NUMERIC);
        foreach (array_keys($remainders) as $i) {
            if ($left <= 0) {
                break;
            }
            $parts[$i]++;
            $left--;
        }

        return array_map(fn (int $p) => $sign * $p, $parts);
    }

    /**
     * @return array{0: list<int>, 1: int} the item lines' nets and the discount line's net (≤ 0)
     */
    private function fromInvoice(Invoice $invoice, int $count): array
    {
        $lines = array_values($invoice->lines ?? []);
        $nets = [];
        $discount = 0;
        foreach ($lines as $i => $line) {
            if (($line['type'] ?? null) === 'discount') {
                $discount += (int) $line['net'];
            } elseif ($i < $count) {
                $nets[$i] = (int) $line['net'];
            }
        }

        return [$nets, $discount];
    }

    /**
     * Orders without an invoice (none in practice since 6B): the same VAT rules on the order itself.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{0: list<int>, 1: int}
     */
    private function withoutInvoice(Order $order, array $items): array
    {
        $amounts = array_map(fn ($item) => (int) $item['amount'], $items);
        if ((int) $order->discount_cents > 0) {
            $amounts[] = -(int) $order->discount_cents;
        }

        $exclusive = (int) $order->vat_cents > 0;
        $taxed = $exclusive || Vat::applies($order->currency, $order->user?->country);
        $rate = $taxed ? Vat::rateBp() : 0;
        $vat = ! $taxed ? 0 : ($exclusive ? (int) $order->vat_cents : Vat::inGross((int) $order->total_cents, $rate));
        $lineVat = $taxed ? Vat::allocate($amounts, $vat, $rate, ! $exclusive) : array_fill(0, count($amounts), 0);

        $nets = [];
        foreach ($amounts as $i => $amount) {
            $nets[$i] = $exclusive ? $amount : $amount - $lineVat[$i];
        }
        $discount = (int) $order->discount_cents > 0 ? array_pop($nets) : 0;

        return [$nets, $discount];
    }
}
