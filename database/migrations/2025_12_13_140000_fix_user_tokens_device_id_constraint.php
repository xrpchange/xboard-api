<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Fixes user_tokens table:
     * 1. Removes UNIQUE constraint on device_id (allows multiple tokens per device for different escrow tokens)
     * 2. Adds escrow_token_hash column if missing
     * 3. Adds index on escrow_token_hash for faster lookups
     * 
     * IMPORTANT: For same escrow_token_hash, there should be only ONE bearer token,
     * regardless of device_id. This is enforced in application logic, not database constraint.
     */
    public function up(): void
    {
        Schema::table('user_tokens', function (Blueprint $table) {
            // Remove UNIQUE constraint on device_id if it exists
            // This allows same device to have multiple bearer tokens for different escrow tokens
            if (Schema::hasIndex('user_tokens', 'user_tokens_device_id_unique')) {
                $table->dropUnique(['device_id']);
            }
            
            // Add escrow_token_hash column if it doesn't exist
            if (!Schema::hasColumn('user_tokens', 'escrow_token_hash')) {
                $table->string('escrow_token_hash', 64)->nullable()->after('collateral_token')
                    ->comment('Hash of escrow token public part (SHA256)');
            }
            
            // Add index on escrow_token_hash for faster lookups
            // This is critical for checking existing tokens before creating new ones
            if (!Schema::hasIndex('user_tokens', 'idx_user_tokens_escrow_token_hash')) {
                $table->index('escrow_token_hash', 'idx_user_tokens_escrow_token_hash');
            }
            
            // Keep index on device_id (but not unique) for faster lookups
            // Index already exists from original migration, so no need to add again
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_tokens', function (Blueprint $table) {
            // Restore UNIQUE constraint on device_id
            $table->unique('device_id', 'user_tokens_device_id_unique');
            
            // Remove index on escrow_token_hash
            $table->dropIndex('idx_user_tokens_escrow_token_hash');
            
            // Note: We don't drop escrow_token_hash column to avoid data loss
            // If needed, create separate migration to drop it
        });
    }
};

