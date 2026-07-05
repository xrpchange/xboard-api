<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * crypto→fiat lots: seller picks escrow token at deal step 1 (payment_details), not at lot create.
     */
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->text('wallet_address')->nullable()->comment('Full public part of escrow token (JSON); null until seller sends payment_details on crypto→fiat')->change();
        });
    }

    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->text('wallet_address')->nullable(false)->comment('Full public part of escrow token (JSON)')->change();
        });
    }
};
