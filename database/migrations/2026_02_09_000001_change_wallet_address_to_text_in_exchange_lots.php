<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * wallet_address — полная открытая часть (public part) эскроу-токена. TEXT, без индекса.
     * Поиск — по wallet_hash (отдельная миграция).
     */
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropIndex(['wallet_address']);
        });
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->text('wallet_address')->comment('Full public part of escrow token (JSON)')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->string('wallet_address', 500)->comment('Open part of escrow account for this crypto')->change();
            $table->index('wallet_address');
        });
    }
};
