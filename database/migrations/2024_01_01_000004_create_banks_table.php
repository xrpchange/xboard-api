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
            $table->id()->comment('Уникальный идентификатор банка');
            $table->foreignId('country_id')->constrained('countries')->comment('Ссылка на страну, в которой находится банк');
            $table->string('name', 100)->comment('Название банка');
            $table->string('swift_code', 11)->unique()->nullable()->comment('SWIFT-код банка');
            $table->boolean('supports_individuals')->default(true)->comment('Работает с физическими лицами');
            $table->boolean('supports_legal_entities')->default(true)->comment('Работает с юридическими лицами');
            $table->boolean('is_active')->default(true)->comment('Флаг активности банка в системе');
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