<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds unique index on (escrow_token_hash, device_id, node_domain) to prevent duplicate Bearer tokens.
     * IMPORTANT: One Bearer token per escrow_token_hash + device_id + node_domain combination.
     */
    public function up(): void
    {
        // First, remove duplicate tokens (keep the most recent one for each combination)
        // This is safe to do because we want only one Bearer token per combination
        DB::statement("
            DELETE t1 FROM user_tokens t1
            INNER JOIN user_tokens t2 
            WHERE t1.id < t2.id 
            AND t1.escrow_token_hash = t2.escrow_token_hash
            AND t1.device_id = t2.device_id
            AND (t1.node_domain = t2.node_domain OR (t1.node_domain IS NULL AND t2.node_domain IS NULL))
            AND t1.expires_at > NOW()
            AND t2.expires_at > NOW()
        ");

        Schema::table('user_tokens', function (Blueprint $table) {
            // Add unique index on escrow_token_hash + device_id + node_domain
            // This prevents creating duplicate Bearer tokens for the same combination
            if (!Schema::hasIndex('user_tokens', 'idx_user_tokens_unique_escrow_device_node')) {
                $table->unique(
                    ['escrow_token_hash', 'device_id', 'node_domain'],
                    'idx_user_tokens_unique_escrow_device_node'
                );
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_tokens', function (Blueprint $table) {
            // Remove unique index
            if (Schema::hasIndex('user_tokens', 'idx_user_tokens_unique_escrow_device_node')) {
                $table->dropUnique('idx_user_tokens_unique_escrow_device_node');
            }
        });
    }
};

