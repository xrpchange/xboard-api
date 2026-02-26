<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop sessions table.
     * API is stateless (escrow token auth); web routes only serve Swagger and do not use sessions.
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
