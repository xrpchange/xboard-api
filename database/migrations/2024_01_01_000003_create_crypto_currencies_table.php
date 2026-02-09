<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Schema aligned with Core GET /info crypto_structure (id from [crypto_currency_ids]).
     * For existing DB: drop table or run a separate migration to alter (drop is_stablecoin, add is_native, network).
     */
    public function up(): void
    {
        Schema::create('crypto_currencies', function (Blueprint $table) {
            $table->id()->comment('Unique cryptocurrency identifier (sync with Core crypto_currency_ids)');
            $table->string('code', 32)->unique()->comment('Cryptocurrency code from Core, e.g. xrp, usdt_stellar');
            $table->string('name', 100)->comment('Display name, e.g. XRP (Ripple), USDT');
            $table->boolean('is_native')->default(false)->comment('True = native chain coin (xrp, btc, xmr), false = token on network');
            $table->string('network', 20)->nullable()->comment('Network from Core: native, stellar, polygon, tron');
            $table->boolean('is_active')->default(true)->comment('Available for use in the system');
            $table->timestamps();

            $table->index('code');
            $table->index('is_native');
            $table->index('network');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crypto_currencies');
    }
};
