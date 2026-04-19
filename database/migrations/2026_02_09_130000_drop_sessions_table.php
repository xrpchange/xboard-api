<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop legacy sessions table (API is stateless for escrow tokens).
     * Recreated later by 2026_04_19_120000_create_sessions_table for Laravel session driver support on web routes.
     */
    public function up(): void
    {
        Schema::dropIfExists('sessions');
    }

    /**
     * Reverse: do not recreate sessions.
     */
    public function down(): void
    {
        //
    }
};
