<?php

namespace App\Services\Marketplace;

use App\Models\AuditLog;
use App\Models\DesignerLedgerEntry;
use App\Models\DesignerPayout;
use App\Models\DesignerProfile;
use App\Models\Order;
use App\Models\Template;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The designers' append-only ledger (docs/MARKETPLACE.md). Each posting is balanced:
 *
 *   sale    D sales N            C designer_payable S       C platform_share N − S
 *   refund  D designer_payable S D platform_share N − S     C sales N
 *   payout  D designer_payable P C payouts_cash P
 *
 * where N is the line's net amount after discount without VAT and S the designer's share
 * (RevenueShare). Amounts are integers in minor units of the order's currency, never converted.
 */
class DesignerLedger
{
    public function __construct(private RevenueShare $revenue) {}

    /**
     * A template_purchase line was paid or approved. Idempotent per order and template.
     */
    public function recordSale(Order $order, int $index, Template $template): ?string
    {
        $designer = $template->designer_id;
        if (! $designer || $this->posted('sale', $order, $template)) {
            return null;
        }

        $profile = DesignerProfile::query()->where('user_id', $designer)->first();
        $rate = $profile?->shareBp() ?? (int) config('marketplace.designer_share_bp', 7000);
        $split = $this->revenue->split($order, $index, $rate);
        if ($split['net'] <= 0) {
            return null;
        }

        $txn = (string) Str::uuid();
        $base = ['txn_id' => $txn, 'designer_id' => $designer, 'kind' => 'sale', 'currency' => $order->currency, 'order_id' => $order->getKey(), 'template_id' => $template->getKey(), 'memo' => $split];

        $this->post([
            $base + ['account' => 'sales', 'debit_cents' => $split['net']],
            $base + ['account' => 'designer_payable', 'credit_cents' => $split['share']],
            $base + ['account' => 'platform_share', 'credit_cents' => $split['platform']],
        ]);

        return $txn;
    }

    /**
     * The order was refunded (6B issues the credit note): the sale's posting reversed.
     */
    public function recordRefund(Order $order, Template|string $template): ?string
    {
        $templateId = $template instanceof Template ? $template->getKey() : $template;
        $sale = DesignerLedgerEntry::query()->where('kind', 'sale')->where('order_id', $order->getKey())->where('template_id', $templateId)->get()->keyBy('account');
        if ($sale->isEmpty() || $this->posted('refund', $order, $templateId)) {
            return null;
        }

        $first = $sale->first();
        $txn = (string) Str::uuid();
        $base = ['txn_id' => $txn, 'designer_id' => $first->designer_id, 'kind' => 'refund', 'currency' => $first->currency, 'order_id' => $order->getKey(), 'template_id' => $templateId, 'memo' => ['reverses' => $first->txn_id]];

        $rows = [];
        foreach ($sale as $account => $entry) {
            $rows[] = $base + ['account' => $account, 'debit_cents' => $entry->credit_cents, 'credit_cents' => $entry->debit_cents];
        }
        $this->post($rows);

        return $txn;
    }

    /**
     * An admin paid a statement by bank transfer: the payable goes down by its amount.
     */
    public function markPaid(DesignerPayout $payout, User $admin, string $reference, ?string $note = null): void
    {
        if (! $admin->isAdmin()) {
            throw new AuthorizationException(trans('app.payments.errors.admins_only'));
        }
        $reference = trim($reference);
        if ($reference === '') {
            throw ValidationException::withMessages(['reference' => trans('validation.required', ['attribute' => 'reference'])]);
        }

        DB::transaction(function () use ($payout, $admin, $reference, $note) {
            $payout = DesignerPayout::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();
            if ($payout->isPaid()) {
                throw ValidationException::withMessages(['payout' => trans('marketplace.errors.payout_paid')]);
            }

            $payout->forceFill(['status' => 'paid', 'reference' => $reference, 'note' => $note, 'paid_at' => now(), 'paid_by' => $admin->getKey()])->save();

            $txn = (string) Str::uuid();
            $base = ['txn_id' => $txn, 'designer_id' => $payout->designer_id, 'kind' => 'payout', 'currency' => $payout->currency, 'payout_id' => $payout->getKey(), 'memo' => ['period' => $payout->period, 'reference' => $reference]];
            $this->post([
                $base + ['account' => 'designer_payable', 'debit_cents' => $payout->amount_cents],
                $base + ['account' => 'payouts_cash', 'credit_cents' => $payout->amount_cents],
            ]);

            AuditLog::record('marketplace.payout_paid', $payout, [
                'designer_id' => $payout->designer_id,
                'amount' => $payout->amount_cents,
                'currency' => $payout->currency,
                'period' => $payout->period,
                'reference' => $reference,
            ], $admin);
        });
    }

    /**
     * What the platform owes a designer now, per currency (credits − debits on designer_payable).
     *
     * @return array<string, int>
     */
    public function balances(string $designerId): array
    {
        return DesignerLedgerEntry::query()
            ->where('designer_id', $designerId)
            ->where('account', 'designer_payable')
            ->groupBy('currency')
            ->selectRaw('currency, sum(credit_cents) - sum(debit_cents) as balance')
            ->pluck('balance', 'currency')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Earned (sales less refunds, before payouts) per currency, optionally up to a moment.
     *
     * @return array<string, int>
     */
    public function earned(string $designerId, ?\DateTimeInterface $before = null): array
    {
        return DesignerLedgerEntry::query()
            ->where('designer_id', $designerId)
            ->where('account', 'designer_payable')
            ->whereIn('kind', ['sale', 'refund'])
            ->when($before, fn ($q) => $q->where('created_at', '<', $before))
            ->groupBy('currency')
            ->selectRaw('currency, sum(credit_cents) - sum(debit_cents) as earned')
            ->pluck('earned', 'currency')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows  one balanced posting
     */
    private function post(array $rows): void
    {
        $debits = array_sum(array_map(fn ($r) => (int) ($r['debit_cents'] ?? 0), $rows));
        $credits = array_sum(array_map(fn ($r) => (int) ($r['credit_cents'] ?? 0), $rows));
        if ($debits !== $credits) {
            throw new \LogicException("Unbalanced ledger posting: {$debits} ≠ {$credits}");
        }

        foreach ($rows as $row) {
            // a zero row (e.g. a 100 % share leaves the platform nothing) is left out
            if ((int) ($row['debit_cents'] ?? 0) === 0 && (int) ($row['credit_cents'] ?? 0) === 0) {
                continue;
            }
            DesignerLedgerEntry::query()->create($row + ['debit_cents' => 0, 'credit_cents' => 0]);
        }
    }

    private function posted(string $kind, Order $order, Template|string $template): bool
    {
        return DesignerLedgerEntry::query()
            ->where('kind', $kind)
            ->where('order_id', $order->getKey())
            ->where('template_id', $template instanceof Template ? $template->getKey() : $template)
            ->exists();
    }
}
