<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Buyer's lot mailbox email when a buyer has started a deal on this lot.
     * Set atomically on "Start deal" (only if NULL); cleared when buyer exits.
     * Lots with this set are excluded from public search.
     */
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->string('buyer_lot_email', 255)->nullable()->after('seller_locale')
                ->comment('Buyer mailbox email while deal in progress; NULL when no active buyer');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropColumn('buyer_lot_email');
        });
    }
};
