<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Pivot table: lots can specify a list of banks from which they accept fiat (optional).
     * Empty list = accept from any bank. Non-empty = only these banks (additional filter).
     */
    public function up(): void
    {
        Schema::create('exchange_lot_banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exchange_lot_id')->constrained('exchange_lots')->cascadeOnDelete();
            $table->foreignId('bank_id')->constrained('banks')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['exchange_lot_id', 'bank_id'], 'exchange_lot_banks_lot_bank_unique');
            $table->index('bank_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_lot_banks');
    }
};
