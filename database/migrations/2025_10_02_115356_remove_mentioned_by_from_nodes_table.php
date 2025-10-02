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
        Schema::table('nodes', function (Blueprint $table) {
            // Удаляем колонку mentioned_by (мы не верим внешним упоминаниям)
            $table->dropColumn('mentioned_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            // Восстанавливаем mentioned_by
            $table->json('mentioned_by')->nullable()->after('trust_level');
        });
    }
};