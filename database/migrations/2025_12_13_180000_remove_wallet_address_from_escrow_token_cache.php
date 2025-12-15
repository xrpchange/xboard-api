<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Removes wallet_address column from escrow_token_cache table.
     * Wallet address is already stored in public_part JSON, no need to duplicate it.
     * This reduces data duplication and improves security (less sensitive data in cache).
     */
    public function up(): void
    {
        Schema::table('escrow_token_cache', function (Blueprint $table) {
            // Remove wallet_address column if it exists
            if (Schema::hasColumn('escrow_token_cache', 'wallet_address')) {
                // Drop index first if it exists
                if (Schema::hasIndex('escrow_token_cache', 'escrow_token_cache_wallet_address_index')) {
                    $table->dropIndex('escrow_token_cache_wallet_address_index');
                }
                // Drop column
                $table->dropColumn('wallet_address');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('escrow_token_cache', function (Blueprint $table) {
            // Restore wallet_address column (but we can't restore the data)
            if (!Schema::hasColumn('escrow_token_cache', 'wallet_address')) {
                $table->string('wallet_address', 255)->nullable()->after('public_part');
                $table->index('wallet_address');
            }
        });
    }
};

