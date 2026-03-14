<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Link each transaction to the buyer it was sent to (buyer_lot_email at send time).
     * Enables per-buyer rules when a lot may have multiple buyers in the future.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('buyer_lot_email', 255)->nullable()->after('exchange_lot_id')
                ->comment('Buyer mailbox email this transaction was sent to; used to filter by buyer (e.g. seller exit block, buyer cancel)');
            $table->index('buyer_lot_email', 'idx_transactions_buyer_lot_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_transactions_buyer_lot_email');
            $table->dropColumn('buyer_lot_email');
        });
    }
};
