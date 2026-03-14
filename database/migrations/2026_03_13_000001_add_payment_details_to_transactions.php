<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Store payment recipient details per transaction: bank details (fiat) or crypto address.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('bank_id')->nullable()->after('exchange_lot_id')->constrained('banks')->nullOnDelete()->comment('Bank for this transaction (from lot / buyer selection)');
            $table->text('bank_details')->nullable()->after('bank_id')->comment('Free-form bank transfer details (card, account, etc.)');
            $table->string('crypto_address', 500)->nullable()->after('bank_details')->comment('Crypto address for this transaction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['bank_id']);
            $table->dropColumn(['bank_details', 'crypto_address']);
        });
    }
};
