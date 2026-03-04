<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Status for "Seller notification" (buyer's message): delivered/read.
     * No server-side mail reading: delivered set when we send; read set when seller's client reports view.
     */
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->timestamp('seller_notification_sent_at')->nullable()->after('buyer_lot_email')
                ->comment('When start_deal email was sent to seller (delivered=true)');
            $table->timestamp('seller_viewed_deal_at')->nullable()->after('seller_notification_sent_at')
                ->comment('When seller client reported viewing the deal (read=true)');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropColumn(['seller_notification_sent_at', 'seller_viewed_deal_at']);
        });
    }
};
