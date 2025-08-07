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
            $table->id()->comment('Уникальный идентификатор лота');
            $table->foreignId('crypto_currency_id')->constrained('crypto_currencies')->comment('ID криптовалюты залогового токена');
            $table->string('wallet_address', 50)->comment('Адрес кошелька залогового токена');
            $table->enum('sell_currency_type', ['fiat', 'crypto'])->comment('Тип продаваемой валюты (фиат/крипто)');
            $table->unsignedBigInteger('sell_currency_id')->comment('ID продаваемой валюты (из fiat_currencies или crypto_currencies)');
            $table->decimal('sell_amount', 20, 8)->comment('Количество продаваемой валюты');
            $table->enum('buy_currency_type', ['fiat', 'crypto'])->comment('Тип покупаемой валюты (фиат/крипто)');
            $table->unsignedBigInteger('buy_currency_id')->comment('ID покупаемой валюты (из fiat_currencies или crypto_currencies)');
            $table->decimal('buy_amount', 20, 8)->comment('Количество покупаемой валюты');
            $table->foreignId('country_id')->nullable()->constrained('countries')->comment('Страна для фиатных операций');
            $table->foreignId('bank_id')->nullable()->constrained('banks')->comment('Банк для фиатных операций');
            $table->string('bank_account', 100)->nullable()->comment('Банковский счет для фиатных операций');
            $table->string('payment_purpose', 255)->nullable()->comment('Назначение платежа для фиатных операций');
            $table->string('contract_url', 255)->nullable()->comment('URL договора для юридических лиц');
            $table->boolean('is_for_individuals')->default(true)->comment('Доступно для физических лиц');
            $table->boolean('is_for_legal_entities')->default(false)->comment('Доступно для юридических лиц');
            $table->boolean('is_active')->default(true)->comment('Активен ли лот');
            $table->boolean('is_test')->default(false)->comment('Тестовый лот для песочницы');
            $table->string('test_group', 50)->nullable()->comment('Группа тестового режима');
            $table->timestamp('expires_at')->nullable()->comment('Дата истечения срока действия лота');
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