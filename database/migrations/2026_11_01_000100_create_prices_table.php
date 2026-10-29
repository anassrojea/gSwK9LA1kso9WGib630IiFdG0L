<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One price per plan or add-on and currency (SYP, SAR, USD). Amounts are in
 * minor units of the exponent in config/payments.php (SYP: whole pounds).
 * plans.price_cents / addons.price_cents stay as the USD fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('priceable_type', 16); // morph alias: plan | addon
            $table->string('priceable_id', 40);
            $table->char('currency', 3);
            $table->bigInteger('amount');
            // seeded guesses until an admin saves a real price in Filament
            $table->boolean('placeholder')->default(false);
            $table->timestampsTz();

            $table->unique(['priceable_type', 'priceable_id', 'currency']);
        });
        Pg::checkIn('prices', 'priceable_type', ['plan', 'addon']);
        Pg::check('prices', 'prices_amount_check', 'amount >= 0');
        Pg::check('prices', 'prices_currency_check', "currency ~ '^[A-Z]{3}$'");
    }

    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
