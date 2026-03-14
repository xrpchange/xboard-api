<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per-side soft delete: seller clearing hides messages only for seller; buyer clearing only for buyer.
     */
    public function up(): void
    {
        Schema::table('deal_chat_messages', function (Blueprint $table) {
            $table->timestamp('deleted_by_seller_at')->nullable()->after('deleted_at');
            $table->timestamp('deleted_by_buyer_at')->nullable()->after('deleted_by_seller_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deal_chat_messages', function (Blueprint $table) {
            $table->dropColumn(['deleted_by_seller_at', 'deleted_by_buyer_at']);
        });
    }
};
