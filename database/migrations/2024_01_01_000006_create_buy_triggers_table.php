<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('buy_triggers', function (Blueprint $table) {
            $table->id()->comment('Unique trigger identifier');
            $table->foreignId('crypto_currency_id')->constrained('crypto_currencies')->comment('Collateral token cryptocurrency ID');
            $table->string('wallet_address', 50)->comment('Collateral token wallet address');
            $table->enum('buy_currency_type', ['fiat', 'crypto'])->comment('Buy currency type (fiat/crypto)');
            $table->unsignedBigInteger('buy_currency_id')->comment('Buy currency ID (from fiat_currencies or crypto_currencies)');
            $table->decimal('buy_amount', 20, 8)->comment('Buy currency amount');
            $table->enum('sell_currency_type', ['fiat', 'crypto'])->comment('Sell currency type (fiat/crypto)');
            $table->unsignedBigInteger('sell_currency_id')->comment('Sell currency ID (from fiat_currencies or crypto_currencies)');
            $table->decimal('max_sell_amount', 20, 8)->nullable()->comment('Maximum sell currency amount');
            $table->foreignId('country_id')->nullable()->constrained('countries')->comment('Country for fiat operations');
            $table->foreignId('bank_id')->nullable()->constrained('banks')->comment('Bank for fiat operations');
            $table->boolean('is_for_individuals')->default(true)->comment('Available for individuals');
            $table->boolean('is_for_legal_entities')->default(false)->comment('Available for legal entities');
            $table->boolean('is_active')->default(true)->comment('Whether trigger is active');
            $table->boolean('is_test')->default(false)->comment('Test trigger for sandbox');
            $table->string('test_group', 50)->nullable()->comment('Test mode group');
            $table->timestamps();
            
            $table->index('crypto_currency_id', 'idx_buy_triggers_crypto_currency_id');
            $table->index('wallet_address', 'idx_buy_triggers_wallet_address');
            $table->index(['is_test', 'test_group'], 'idx_buy_triggers_test');
            $table->index(['buy_currency_type', 'buy_currency_id'], 'idx_buy_triggers_buy_currency');
            $table->index(['sell_currency_type', 'sell_currency_id'], 'idx_buy_triggers_sell_currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('buy_triggers');
    }
}; 