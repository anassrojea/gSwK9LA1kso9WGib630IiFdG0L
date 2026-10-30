<?php

namespace App\Services\Marketplace;

use App\Models\AuditLog;
use App\Models\DesignerLedgerEntry;
use App\Models\DesignerPayout;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Monthly payout statements (marketplace:payout-statements, on the 1st for the month before).
 *
 * For each designer and currency, the statement for a month is what they earned (sales less
 * refunds, designer_payable) up to the end of that month, less every earlier statement. It is
 * only issued when that reaches config('marketplace.payout_minimum.{CURRENCY}'); below it (or
 * negative after refunds) the amount simply carries into a later month. Running it twice for a
 * month changes nothing (one statement per designer, currency and month).
 */
class PayoutStatements
{
    /**
     * @return list<DesignerPayout> the statements issued now
     */
    public function run(CarbonImmutable $month): array
    {
        $period = $month->format('Y-m');
        $end = $month->startOfMonth()->addMonth();
        $issued = [];

        $pairs = DesignerLedgerEntry::query()
            ->where('account', 'designer_payable')
            ->whereIn('kind', ['sale', 'refund'])
            ->where('created_at', '<', $end)
            ->distinct()
            ->get(['designer_id', 'currency']);

        foreach ($pairs as $pair) {
            $payout = DB::transaction(fn () => $this->statement($pair->designer_id, $pair->currency, $period, $month->startOfMonth(), $end));
            if ($payout) {
                $issued[] = $payout;
            }
        }

        return $issued;
    }

    private function statement(string $designerId, string $currency, string $period, CarbonImmutable $start, CarbonImmutable $end): ?DesignerPayout
    {
        if (DesignerPayout::query()->where('designer_id', $designerId)->where('currency', $currency)->where('period', $period)->exists()) {
            return null;
        }

        $earned = (int) DesignerLedgerEntry::query()
            ->where('designer_id', $designerId)
            ->where('currency', $currency)
            ->where('account', 'designer_payable')
            ->whereIn('kind', ['sale', 'refund'])
            ->where('created_at', '<', $end)
            ->selectRaw('coalesce(sum(credit_cents) - sum(debit_cents), 0) as earned')
            ->value('earned');

        $before = (int) DesignerPayout::query()
            ->where('designer_id', $designerId)
            ->where('currency', $currency)
            ->where('period', '<', $period)
            ->sum('amount_cents');

        $amount = $earned - $before;
        $minimum = (int) config("marketplace.payout_minimum.{$currency}", 0);
        if ($amount <= 0 || $amount < $minimum) {
            return null;
        }

        // the month's own activity, for the statement's lines
        $month = DesignerLedgerEntry::query()
            ->where('designer_id', $designerId)
            ->where('currency', $currency)
            ->where('account', 'designer_payable')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end);

        $payout = DesignerPayout::query()->create([
            'designer_id' => $designerId,
            'currency' => $currency,
            'period' => $period,
            'amount_cents' => $amount,
            'sales_cents' => (int) (clone $month)->where('kind', 'sale')->sum('credit_cents'),
            'refunds_cents' => (int) (clone $month)->where('kind', 'refund')->sum('debit_cents'),
            'sales_count' => (clone $month)->where('kind', 'sale')->count(),
        ]);

        AuditLog::record('marketplace.payout_statement', $payout, ['designer_id' => $designerId, 'amount' => $amount, 'currency' => $currency, 'period' => $period]);

        return $payout;
    }
}
