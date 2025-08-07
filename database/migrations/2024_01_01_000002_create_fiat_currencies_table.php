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
        Schema::create('fiat_currencies', function (Blueprint $table) {
            $table->id()->comment('Уникальный идентификатор валюты');
            $table->string('code', 3)->unique()->comment('ISO 4217 код валюты (3 буквы)');
            $table->string('name', 50)->comment('Название валюты');
            $table->string('symbol', 5)->nullable()->comment('Символ валюты ($, € и т.д.)');
            $table->boolean('is_active')->default(true)->comment('Флаг активности валюты в системе');
            $table->timestamps();
            
            $table->index('code', 'idx_fiat_currencies_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiat_currencies');
    }
}; 