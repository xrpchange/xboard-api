<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->decimal('sold_crypto_amount', 20, 8)
                ->default(0)
                ->after('sell_amount')
                ->comment('Cumulative crypto (sale side) from completed sale transactions removed from DB; supports partial lot sales after prune/purge.');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropColumn('sold_crypto_amount');
        });
    }
};
