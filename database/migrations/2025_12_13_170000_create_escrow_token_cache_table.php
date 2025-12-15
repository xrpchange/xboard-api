<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates escrow_token_cache table for caching escrow token validation.
     * This is a simple cache to avoid calling Core API on every request.
     * Uses publicPart (open part of escrow token) as the key.
     * 
     * This replaces the complex Bearer token system with a simple cache:
     * - No token expiration management
     * - No refresh logic
     * - No token versioning
     * - Easier to maintain and migrate
     */
    public function up(): void
    {
        Schema::create('escrow_token_cache', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique()->comment('SHA256 hash of publicPart - used as key');
            // NOTE: public_part is NOT stored - it comes from client in every request
            // We only cache validation metadata to avoid calling Core API on every request
            $table->string('currency', 10)->comment('Currency: XRP, BTC, XMR, etc. (extracted from publicPart for indexing)');
            $table->string('node_domain', 255)->nullable()->comment('Node domain where token was created (extracted from publicPart)');
            $table->string('environment', 20)->default('sandbox')->comment('Environment: sandbox, production (extracted from publicPart)');
            $table->string('device_id', 255)->nullable()->comment('Device ID (for statistics)');
            $table->string('ip_address', 45)->nullable()->comment('IP address (for statistics)');
            $table->timestamp('last_validated_at')->nullable()->comment('Last time token was validated via Core API');
            $table->timestamp('last_used_at')->nullable()->comment('Last time token was used for authentication');
            $table->timestamps();
            
            // Indexes for fast lookups
            $table->index('token_hash');
            $table->index('currency');
            $table->index('node_domain');
            $table->index('last_used_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('escrow_token_cache');
    }
};

