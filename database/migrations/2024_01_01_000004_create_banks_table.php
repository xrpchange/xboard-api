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
        Schema::create('banks', function (Blueprint $table) {
            $table->id()->comment('Unique bank identifier');
            $table->foreignId('country_id')->constrained('countries')->comment('Reference to the country where the bank is located');
            $table->string('name', 100)->comment('Bank name');
            $table->string('swift_code', 11)->unique()->nullable()->comment('Bank SWIFT code');
            $table->boolean('supports_individuals')->default(true)->comment('Works with individuals');
            $table->boolean('supports_legal_entities')->default(true)->comment('Works with legal entities');
            $table->boolean('is_active')->default(true)->comment('Bank activity flag in the system');
            $table->timestamps();
            
            $table->index('country_id', 'idx_banks_country_id');
            $table->index('swift_code', 'idx_banks_swift_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banks');
    }
}; 