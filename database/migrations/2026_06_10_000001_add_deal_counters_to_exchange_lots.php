<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->unsignedInteger('completed_deals_count')
                ->default(0)
                ->after('sold_crypto_amount')
                ->comment('Completed sale legs; not reset when lot is edited');
            $table->unsignedInteger('bad_deals_count')
                ->default(0)
                ->after('completed_deals_count')
                ->comment('Disputes where buyer was compensated from seller escrow');
        });

        // Backfill completed deals from historical completed sale transactions.
        if (Schema::hasTable('transactions')) {
            DB::statement("
                UPDATE exchange_lots el
                SET completed_deals_count = (
                    SELECT COUNT(*)
                    FROM transactions t
                    WHERE t.exchange_lot_id = el.id
                      AND t.tx_type = 'sale'
                      AND t.status = 'completed'
                )
            ");
        }
    }

    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropColumn(['completed_deals_count', 'bad_deals_count']);
        });
    }
};
