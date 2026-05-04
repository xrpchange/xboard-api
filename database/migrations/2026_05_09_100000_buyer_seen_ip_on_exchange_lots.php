<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table): void {
            $table->string('buyer_seen_ip', 45)->nullable()->after('buyer_lot_email');
            $table->boolean('buyer_ip_share_with_seller')->default(false)->after('buyer_seen_ip');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table): void {
            $table->dropColumn(['buyer_seen_ip', 'buyer_ip_share_with_seller']);
        });
    }
};
