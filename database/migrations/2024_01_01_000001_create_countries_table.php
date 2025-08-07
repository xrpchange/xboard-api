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
        Schema::create('countries', function (Blueprint $table) {
            $table->id()->comment('Уникальный идентификатор страны');
            $table->string('name', 100)->comment('Название страны');
            $table->string('code', 3)->unique()->comment('ISO 3166-1 alpha-3 код страны (3 буквы)');
            $table->string('phone_code', 10)->nullable()->comment('Телефонный код страны');
            $table->boolean('is_active')->default(true)->comment('Флаг активности страны в системе');
            $table->timestamps();
            
            $table->index('code', 'idx_countries_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
}; 