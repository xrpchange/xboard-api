<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seller's locale (e.g. en, ru) for localizing deal emails (subject) sent to the seller.
     * No backward compatibility: lots are cleared before deploy.
     */
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->string('seller_locale', 10)->after('note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropColumn('seller_locale');
        });
    }
};
