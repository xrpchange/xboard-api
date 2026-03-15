<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * buyer_payment_timeout_minutes: time (in minutes) the buyer has to pay and, for bank payments, provide receipt; after that seller can exit the deal.
     * seller_notification_email: email address (from Settings) to receive notification when buyer enters the Deal screen.
     */
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->unsignedSmallInteger('buyer_payment_timeout_minutes')->default(30)->after('aml');
            $table->string('seller_notification_email', 255)->nullable()->after('buyer_payment_timeout_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropColumn(['buyer_payment_timeout_minutes', 'seller_notification_email']);
        });
    }
};
