<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->boolean('escrow_multisig_2_of_3_required')
                ->default(false)
                ->after('sold_crypto_amount');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropColumn('escrow_multisig_2_of_3_required');
        });
    }
};
