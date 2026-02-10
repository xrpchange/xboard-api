<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Связь: какие банки какие фиатные валюты поддерживают.
     */
    public function up(): void
    {
        Schema::create('bank_fiat_currency', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained('banks')->cascadeOnDelete();
            $table->foreignId('fiat_currency_id')->constrained('fiat_currencies')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['bank_id', 'fiat_currency_id'], 'bank_fiat_currency_bank_id_fiat_currency_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_fiat_currency');
    }
};
