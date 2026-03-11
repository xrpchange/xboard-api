<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Buyer's selected amount when they started the deal (buy amount in to_currency).
     * Allows the seller to see and copy the amount on the "Deal in progress" screen.
     */
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->decimal('buyer_selected_amount', 24, 8)->nullable()->after('buyer_lot_email')
                ->comment('Amount the buyer selected when starting the deal (buy amount, to_currency)');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropColumn('buyer_selected_amount');
        });
    }
};
