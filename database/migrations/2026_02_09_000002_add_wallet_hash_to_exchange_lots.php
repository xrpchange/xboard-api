<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * wallet_hash — SHA256 от wallet_address для быстрого поиска по индексу.
     * wallet_address не индексируем (TEXT, полная public part).
     */
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->string('wallet_hash', 64)->nullable()->after('wallet_address')
                ->comment('SHA256 hex of wallet_address for indexed lookup');
        });

        // Backfill hash for existing rows
        \Illuminate\Support\Facades\DB::statement(
            "UPDATE exchange_lots SET wallet_hash = LOWER(HEX(SHA2(wallet_address, 256))) WHERE wallet_address IS NOT NULL AND wallet_hash IS NULL"
        );

        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->index('wallet_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropIndex(['wallet_hash']);
            $table->dropColumn('wallet_hash');
        });
    }
};
