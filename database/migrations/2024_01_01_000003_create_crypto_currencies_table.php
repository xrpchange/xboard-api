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
            $table->id()->comment('Unique cryptocurrency identifier');
            $table->string('code', 10)->unique()->comment('Cryptocurrency ticker (BTC, ETH, etc.)');
            $table->string('name', 50)->comment('Cryptocurrency name');
            $table->boolean('is_stablecoin')->default(false)->comment('Stablecoin flag');
            $table->boolean('is_active')->default(true)->comment('Cryptocurrency activity flag in the system');
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