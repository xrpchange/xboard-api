<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add rate column to exchange_lots.
 * - When buy_amounts has exactly one value: rate = buy_amount / sell_amount (computed on save).
 * - When buy_amounts has multiple values: rate must be provided by the seller (required in create/update).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->decimal('rate', 20, 8)->nullable()->after('buy_amounts')
                ->comment('Exchange rate (buy/sell). Required when multiple buy_amounts; computed when single.');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropColumn('rate');
        });
    }
};
