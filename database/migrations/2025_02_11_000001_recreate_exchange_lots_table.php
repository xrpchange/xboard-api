<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Новая структура exchange_lots в соответствии с экраном создания лота:
     * - Валюты кодами (from_currency, to_currency), без country_id и без sell/buy_currency_type/id.
     * - Суммы «получаем»: buy_amount_min, buy_amount_max, buy_amounts (JSON).
     * - wallet_address — открытая часть эскроу-счёта для крипты.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['exchange_lot_id']);
        });
        Schema::dropIfExists('exchange_lots');

        Schema::create('exchange_lots', function (Blueprint $table) {
            $table->id()->comment('Unique lot identifier');
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete()->comment('Node this lot belongs to');
            $table->string('from_currency', 20)->comment('Currency code we give (e.g. USD, XRP)');
            $table->string('to_currency', 20)->comment('Currency code we receive (e.g. XRP, BTC)');
            $table->decimal('sell_amount', 20, 8)->comment('Total amount we give');
            $table->decimal('buy_amount_min', 20, 8)->comment('Minimum of buy_amounts array');
            $table->decimal('buy_amount_max', 20, 8)->comment('Maximum of buy_amounts array');
            $table->json('buy_amounts')->comment('Array of amounts we want to receive (sale in parts)');
            $table->foreignId('crypto_currency_id')->constrained('crypto_currencies')->comment('Crypto from Core (lot currency)');
            $table->string('wallet_address', 500)->comment('Open part of escrow account for this crypto');
            $table->foreignId('bank_id')->nullable()->constrained('banks')->comment('Optional bank for fiat');
            $table->string('bank_account', 100)->nullable();
            $table->string('payment_purpose', 255)->nullable();
            $table->string('contract_url', 255)->nullable();
            $table->boolean('is_for_individuals')->default(true);
            $table->boolean('is_for_legal_entities')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_test')->default(false);
            $table->string('test_group', 50)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('node_id');
            $table->index(['from_currency', 'to_currency']);
            $table->index('crypto_currency_id');
            $table->index('wallet_address');
            $table->index(['is_test', 'test_group']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('exchange_lot_id')->references('id')->on('exchange_lots')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_lots');
        // exchange_lot_id в transactions остаётся без FK (восстановление старой схемы — отдельная миграция)
    }
};
