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
            $table->id()->comment('Unique country identifier');
            $table->string('name', 100)->comment('Country name');
            $table->string('code', 3)->unique()->comment('ISO 3166-1 alpha-3 country code (3 letters)');
            $table->string('phone_code', 10)->nullable()->comment('Country phone code');
            $table->boolean('is_active')->default(true)->comment('Country activity flag in the system');
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