<?php

namespace App\Services\Invoicing;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Sequential, gap-free document numbers per series: INV-2026-000001, 000002, …
 *
 * The counter row is locked (SELECT … FOR UPDATE) and bumped inside the
 * caller's transaction, the same one that writes the invoice and marks the
 * order paid. Concurrent issuers wait on the row lock; a rolled-back
 * transaction gives its number back. A PostgreSQL sequence would be faster but
 * leaves holes on rollback, which an auditor reads as a missing invoice.
 */
class InvoiceNumbers
{
    /**
     * @return array{series: string, sequence: int, number: string}
     */
    public function next(string $prefix, int $year): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Invoice numbers are taken inside the transaction that issues the invoice.');
        }

        $series = "{$prefix}-{$year}";

        // first number of the series: create the row; a concurrent creator makes this a no-op
        DB::table('invoice_counters')->insertOrIgnore([
            'series' => $series,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $last = (int) DB::table('invoice_counters')->where('series', $series)->lockForUpdate()->value('last_number');
        $sequence = $last + 1;

        DB::table('invoice_counters')->where('series', $series)->update(['last_number' => $sequence, 'updated_at' => now()]);

        return [
            'series' => $series,
            'sequence' => $sequence,
            'number' => $series.'-'.str_pad((string) $sequence, (int) config('invoicing.digits', 6), '0', STR_PAD_LEFT),
        ];
    }
}
