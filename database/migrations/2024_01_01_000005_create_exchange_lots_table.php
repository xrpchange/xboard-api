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
        Schema::create('exchange_lots', function (Blueprint $table) {
            $table->id()->comment('Unique lot identifier');
            $table->foreignId('crypto_currency_id')->constrained('crypto_currencies')->comment('Collateral token cryptocurrency ID');
            $table->string('wallet_address', 50)->comment('Collateral token wallet address');
            $table->enum('sell_currency_type', ['fiat', 'crypto'])->comment('Sell currency type (fiat/crypto)');
            $table->unsignedBigInteger('sell_currency_id')->comment('Sell currency ID (from fiat_currencies or crypto_currencies)');
            $table->decimal('sell_amount', 20, 8)->comment('Sell currency amount');
            $table->enum('buy_currency_type', ['fiat', 'crypto'])->comment('Buy currency type (fiat/crypto)');
            $table->unsignedBigInteger('buy_currency_id')->comment('Buy currency ID (from fiat_currencies or crypto_currencies)');
            $table->decimal('buy_amount', 20, 8)->comment('Buy currency amount');
            $table->foreignId('country_id')->nullable()->constrained('countries')->comment('Country for fiat operations');
            $table->foreignId('bank_id')->nullable()->constrained('banks')->comment('Bank for fiat operations');
            $table->string('bank_account', 100)->nullable()->comment('Bank account for fiat operations');
            $table->string('payment_purpose', 255)->nullable()->comment('Payment purpose for fiat operations');
            $table->string('contract_url', 255)->nullable()->comment('Contract URL for legal entities');
            $table->boolean('is_for_individuals')->default(true)->comment('Available for individuals');
            $table->boolean('is_for_legal_entities')->default(false)->comment('Available for legal entities');
            $table->boolean('is_active')->default(true)->comment('Whether lot is active');
            $table->boolean('is_test')->default(false)->comment('Test lot for sandbox');
            $table->string('test_group', 50)->nullable()->comment('Test mode group');
            $table->timestamp('expires_at')->nullable()->comment('Lot expiration date');
            $table->timestamps();
            
            $table->index('crypto_currency_id', 'idx_exchange_lots_crypto_currency_id');
            $table->index('wallet_address', 'idx_exchange_lots_wallet_address');
            $table->index(['is_test', 'test_group'], 'idx_exchange_lots_test');
            $table->index(['sell_currency_type', 'sell_currency_id'], 'idx_exchange_lots_sell_currency');
            $table->index(['buy_currency_type', 'buy_currency_id'], 'idx_exchange_lots_buy_currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_lots');
    }
}; 