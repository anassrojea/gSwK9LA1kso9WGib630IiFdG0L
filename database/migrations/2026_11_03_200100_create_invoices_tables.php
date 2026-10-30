<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices, receipts and credit notes (phase 6B, docs/INVOICES.md).
 *
 * invoice_counters: one row per series ("INV-2026", "CN-2026"). A number is taken
 * by locking the row (SELECT … FOR UPDATE) inside the same transaction that
 * marks the order paid, so a rollback gives the number back: numbers are
 * sequential and gap-free, which a PostgreSQL sequence would not guarantee.
 *
 * Amounts are integers in minor units of the currency (App\Services\Payments\Currencies),
 * the VAT rate in basis points (1500 = 15 %). Documents are never edited or
 * deleted: a refund issues a credit note pointing at the invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_counters', function (Blueprint $table) {
            $table->string('series', 32)->primary();
            $table->integer('last_number')->default(0);
            $table->timestampsTz();
        });
        Pg::check('invoice_counters', 'invoice_counters_last_number_check', 'last_number >= 0');

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // INV-2026-000123: series + zero-padded sequence
            $table->string('number', 40)->unique();
            $table->string('series', 32);
            $table->integer('sequence');
            $table->string('kind', 16);  // invoice | credit_note
            $table->string('type', 32);  // simplified_tax_invoice | receipt
            // the invoice a credit note reverses
            $table->uuid('credit_for_id')->nullable();
            // kept for the accountant even when the customer deletes their account
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('customer');  // {name, email, country}
            $table->jsonb('seller');    // {name_ar, name_en, vat_number, cr_number, address_ar, address_en, email}
            // [{type, id, name_ar, name_en, quantity, unit_amount, amount, net, vat}]; a discount line has a negative amount
            $table->jsonb('lines');
            $table->char('currency', 3);
            $table->bigInteger('gross_cents');     // the lines before the discount (VAT included)
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('subtotal_cents');  // net: total less VAT
            $table->bigInteger('vat_cents')->default(0);
            $table->bigInteger('total_cents');     // what the customer paid (negative for a credit note)
            $table->integer('vat_rate_bp')->default(0);
            $table->boolean('prices_include_vat')->default(true);
            $table->string('locale', 5)->default('ar');
            $table->timestampTz('issued_at');
            // ZATCA phase-1 QR payload (TLV, base64), simplified tax invoices only
            $table->text('qr')->nullable();
            // cached PDF on the media disk, rendered on first download
            $table->string('pdf_path')->nullable();
            $table->timestampsTz();

            $table->unique(['series', 'sequence']);
            // one invoice and at most one credit note per order
            $table->unique(['order_id', 'kind']);
            $table->index(['issued_at']);
            $table->index(['user_id', 'issued_at']);
        });
        // self reference: added once the primary key exists
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('credit_for_id')->references('id')->on('invoices')->restrictOnDelete();
        });
        Pg::checkIn('invoices', 'kind', ['invoice', 'credit_note']);
        Pg::checkIn('invoices', 'type', ['simplified_tax_invoice', 'receipt']);
        Pg::check('invoices', 'invoices_credit_check', "(kind = 'credit_note') = (credit_for_id is not null)");
        Pg::check('invoices', 'invoices_vat_check', 'vat_cents = 0 or type = \'simplified_tax_invoice\'');

        // VAT added on top at checkout when prices are configured VAT-exclusive (INVOICE_PRICES_INCLUDE_VAT=false);
        // total_cents already includes it. Always 0 with VAT-inclusive prices.
        Schema::table('orders', function (Blueprint $table) {
            $table->bigInteger('vat_cents')->default(0)->after('discount_cents');
        });
        Pg::check('orders', 'orders_vat_cents_check', 'vat_cents >= 0');
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('vat_cents');
        });
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_counters');
    }
};
