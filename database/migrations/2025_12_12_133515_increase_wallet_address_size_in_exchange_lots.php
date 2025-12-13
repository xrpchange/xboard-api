<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            // Increase wallet_address size to support:
            // - Stellar addresses: 56 characters (G...)
            // - XMR addresses: JSON format with primary and view_key (can be 300+ characters)
            // - XRP addresses: 34 characters (r...)
            // - BTC addresses: up to 62 characters (bech32)
            $table->string('wallet_address', 500)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            // Revert to original size
            $table->string('wallet_address', 50)->change();
        });
    }
};
