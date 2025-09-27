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
            $table->id()->comment('Unique currency identifier');
            $table->string('code', 3)->unique()->comment('ISO 4217 currency code (3 letters)');
            $table->string('name', 50)->comment('Currency name');
            $table->string('symbol', 5)->nullable()->comment('Currency symbol ($, €, etc.)');
            $table->boolean('is_active')->default(true)->comment('Currency activity flag in the system');
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