<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The designers' money (docs/MARKETPLACE.md, "Revenue share").
 *
 * designer_ledger_entries: append-only, double-entry style. Every posting (txn_id) has
 * balanced debits and credits in one currency:
 *
 *   sale     debit  sales             net        credit designer_payable  share    credit platform_share  net − share
 *   refund   the same rows reversed (debit designer_payable, …)
 *   payout   debit  designer_payable  amount     credit payouts_cash      amount
 *
 * A designer's balance is credits − debits on designer_payable. Rows are never updated
 * or deleted (a trigger refuses it on PostgreSQL); a mistake is fixed with a new posting.
 *
 * designer_payouts: the monthly statement per designer and currency, marked paid by an admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('designer_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('designer_id')->constrained('users')->restrictOnDelete();
            $table->char('currency', 3);
            // YYYY-MM: the month the statement closes
            $table->char('period', 7);
            $table->bigInteger('amount_cents');
            $table->bigInteger('sales_cents')->default(0);
            $table->bigInteger('refunds_cents')->default(0);
            $table->integer('sales_count')->default(0);
            $table->string('status', 16)->default('pending');
            $table->string('reference', 120)->nullable();
            $table->text('note')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->foreignUuid('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['designer_id', 'currency', 'period']);
            $table->index(['status', 'currency']);
        });
        Pg::checkIn('designer_payouts', 'status', ['pending', 'paid']);
        Pg::check('designer_payouts', 'designer_payouts_amount_check', 'amount_cents > 0');
        Pg::check('designer_payouts', 'designer_payouts_paid_check', "(status = 'paid') = (paid_at is not null and reference is not null)");

        Schema::create('designer_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('txn_id');
            $table->foreignUuid('designer_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 16);      // sale | refund | payout
            $table->string('account', 24);   // sales | designer_payable | platform_share | payouts_cash
            $table->char('currency', 3);
            $table->bigInteger('debit_cents')->default(0);
            $table->bigInteger('credit_cents')->default(0);
            $table->foreignUuid('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->string('template_id', 64)->nullable();
            $table->foreignUuid('payout_id')->nullable()->constrained('designer_payouts')->restrictOnDelete();
            // the math behind a sale: net line amount, rate (bp), share
            $table->jsonb('memo')->nullable();
            $table->timestampTz('created_at');

            $table->index(['designer_id', 'currency', 'account']);
            $table->index('txn_id');
            $table->index('order_id');
        });
        Pg::checkIn('designer_ledger_entries', 'kind', ['sale', 'refund', 'payout']);
        Pg::checkIn('designer_ledger_entries', 'account', ['sales', 'designer_payable', 'platform_share', 'payouts_cash']);
        Pg::check('designer_ledger_entries', 'designer_ledger_entries_amount_check', 'debit_cents >= 0 and credit_cents >= 0 and (debit_cents = 0) <> (credit_cents = 0)');

        if (Pg::active()) {
            // one sale and one refund posting per order line (template), one payout posting per statement
            DB::statement("create unique index designer_ledger_once on designer_ledger_entries (kind, order_id, template_id, account) where kind in ('sale', 'refund')");
            DB::statement("create unique index designer_ledger_payout_once on designer_ledger_entries (payout_id, account) where kind = 'payout'");

            DB::statement(<<<'SQL'
                create or replace function designer_ledger_append_only() returns trigger as $$
                begin
                    raise exception 'designer_ledger_entries is append-only';
                end;
                $$ language plpgsql
                SQL);
            DB::statement('create trigger designer_ledger_no_update before update or delete on designer_ledger_entries for each row execute function designer_ledger_append_only()');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('designer_ledger_entries');
        if (Pg::active()) {
            DB::statement('drop function if exists designer_ledger_append_only()');
        }
        Schema::dropIfExists('designer_payouts');
    }
};
