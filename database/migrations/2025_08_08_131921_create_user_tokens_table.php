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
        Schema::create('user_tokens', function (Blueprint $table) {
            $table->id()->comment('Уникальный идентификатор токена');
            $table->string('token', 64)->unique()->comment('Хэш Bearer-токена');
            $table->string('device_id', 255)->unique()->comment('Уникальный ID устройства');
            $table->text('collateral_token')->nullable()->comment('Залоговый токен');
            $table->string('ip_address', 45)->comment('IP создания токена');
            $table->timestamp('last_used_at')->nullable()->comment('Время последнего использования');
            $table->timestamp('expires_at')->nullable()->comment('Время истечения токена');
            $table->timestamps();
            
            $table->index('token', 'idx_user_tokens_token');
            $table->index('device_id', 'idx_user_tokens_device_id');
            $table->index('expires_at', 'idx_user_tokens_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_tokens');
    }
};
