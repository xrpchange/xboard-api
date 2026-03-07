<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seller's lot mailbox email (for chat/IMAP). Set when the lot is created and a mailbox is created.
     * Allows the client to know which email belongs to this lot when loading "My lots" from API.
     */
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->string('seller_lot_email', 255)->nullable()->after('buyer_lot_email')
                ->comment('Seller mailbox email for this lot (IMAP chat); set on lot create when mailbox is created');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropColumn('seller_lot_email');
        });
    }
};
