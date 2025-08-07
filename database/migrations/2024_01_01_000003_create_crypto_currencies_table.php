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
        Schema::create('crypto_currencies', function (Blueprint $table) {
            $table->id()->comment('Уникальный идентификатор криптовалюты');
            $table->string('code', 10)->unique()->comment('Тикер криптовалюты (BTC, ETH и т.д.)');
            $table->string('name', 50)->comment('Название криптовалюты');
            $table->boolean('is_stablecoin')->default(false)->comment('Флаг стейблкоина');
            $table->boolean('is_active')->default(true)->comment('Флаг активности криптовалюты в системе');
            $table->timestamps();
            
            $table->index('code', 'idx_crypto_currencies_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crypto_currencies');
    }
}; 