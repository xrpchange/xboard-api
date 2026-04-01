<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * buyer_notification_email: optional external notification email for the buyer (from Settings), used for emergency deal notifications.
     */
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->string('buyer_notification_email', 255)->nullable()->after('seller_notification_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropColumn('buyer_notification_email');
        });
    }
};

