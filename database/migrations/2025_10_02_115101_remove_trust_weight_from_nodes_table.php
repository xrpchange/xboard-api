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
        Schema::table('nodes', function (Blueprint $table) {
            // Удаляем индексы, содержащие trust_weight
            $table->dropIndex(['is_trusted', 'trust_level', 'trust_weight']);
            $table->dropIndex(['is_active', 'user_connections', 'trust_weight']);
            
            // Удаляем колонку trust_weight
            $table->dropColumn('trust_weight');
            
            // Создаем новые индексы без trust_weight
            $table->index(['is_trusted', 'trust_level']);
            $table->index(['is_active', 'user_connections']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            // Удаляем новые индексы
            $table->dropIndex(['is_trusted', 'trust_level']);
            $table->dropIndex(['is_active', 'user_connections']);
            
            // Восстанавливаем trust_weight
            $table->integer('trust_weight')->default(0)->after('trust_level');
            
            // Восстанавливаем старые индексы
            $table->index(['is_trusted', 'trust_level', 'trust_weight']);
            $table->index(['is_active', 'user_connections', 'trust_weight']);
        });
    }
};