<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Buyer's escrow token hash (from start_deal). Used to allow only buyer to cancel deal when seller has initiated a transaction.
     */
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->string('buyer_escrow_token_hash', 64)->nullable()->after('buyer_lot_email')->comment('SHA256 hash of buyer escrow token public part');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropColumn('buyer_escrow_token_hash');
        });
    }
};
