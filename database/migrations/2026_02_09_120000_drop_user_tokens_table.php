<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop user_tokens table.
     * Bearer tokens removed in favor of escrow_token_cache (escrow token authentication only).
     */
    public function up(): void
    {
        Schema::dropIfExists('user_tokens');
    }

    /**
     * Reverse: do not recreate user_tokens.
     */
    public function down(): void
    {
        // Table structure can be restored from previous migrations if needed
    }
};
