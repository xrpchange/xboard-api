<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds node_domain column to user_tokens table.
     * IMPORTANT: Each node should have its own Bearer token.
     * Escrow token gives the right to obtain Bearer token, but Bearer tokens are different for different nodes.
     */
    public function up(): void
    {
        Schema::table('user_tokens', function (Blueprint $table) {
            // Add node_domain column if it doesn't exist
            if (!Schema::hasColumn('user_tokens', 'node_domain')) {
                $table->string('node_domain', 255)->nullable()->after('escrow_token_hash')
                    ->comment('Node domain - one Bearer token per node');
            }
            
            // Add index on node_domain for faster lookups
            // This is critical for checking existing tokens before creating new ones
            if (!Schema::hasIndex('user_tokens', 'idx_user_tokens_node_domain')) {
                $table->index('node_domain', 'idx_user_tokens_node_domain');
            }
            
            // Add composite index for faster lookups: escrow_token_hash + device_id + node_domain
            // This ensures one Bearer token per node, even if using the same escrow token
            if (!Schema::hasIndex('user_tokens', 'idx_user_tokens_escrow_device_node')) {
                $table->index(['escrow_token_hash', 'device_id', 'node_domain'], 'idx_user_tokens_escrow_device_node');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_tokens', function (Blueprint $table) {
            // Remove composite index
            $table->dropIndex('idx_user_tokens_escrow_device_node');
            
            // Remove index on node_domain
            $table->dropIndex('idx_user_tokens_node_domain');
            
            // Note: We don't drop node_domain column to avoid data loss
            // If needed, create separate migration to drop it
        });
    }
};

