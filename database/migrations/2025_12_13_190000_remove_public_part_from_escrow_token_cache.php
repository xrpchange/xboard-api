<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Removes public_part column from escrow_token_cache table.
     * public_part is always sent by client in X-Escrow-Token header, no need to store it.
     * We only cache validation metadata (currency, node_domain, environment, validation timestamps).
     */
    public function up(): void
    {
        Schema::table('escrow_token_cache', function (Blueprint $table) {
            // Remove public_part column if it exists
            if (Schema::hasColumn('escrow_token_cache', 'public_part')) {
                $table->dropColumn('public_part');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('escrow_token_cache', function (Blueprint $table) {
            // Restore public_part column (but we can't restore the data)
            if (!Schema::hasColumn('escrow_token_cache', 'public_part')) {
                $table->text('public_part')->nullable()->after('token_hash');
            }
        });
    }
};

