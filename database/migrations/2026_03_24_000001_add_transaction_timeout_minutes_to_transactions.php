<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Store timeout minutes per deal transaction to make exit rules independent for each transaction.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedSmallInteger('transaction_timeout_minutes')
                ->nullable()
                ->after('crypto_address')
                ->comment('Per-transaction timeout window in minutes for deal exit lock');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('transaction_timeout_minutes');
        });
    }
};
