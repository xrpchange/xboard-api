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
            // Система доверия (trust_weight рассчитывается только в клиенте)
            $table->boolean('is_trusted')->default(false)->after('is_active'); // Доверенный узел
            $table->integer('trust_level')->default(0)->after('is_trusted'); // Уровень доверия (0-10)
            $table->json('mentioned_by')->nullable()->after('trust_level'); // Список ID узлов, которые упоминают этот узел
            
            // Подключения пользователей
            $table->integer('user_connections')->default(0)->after('mentioned_by'); // Количество активных подключений
            
            // Индексы для оптимизации сортировки
            $table->index(['is_trusted', 'trust_level']);
            $table->index('user_connections');
            $table->index(['is_active', 'user_connections']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropIndex(['is_trusted', 'trust_level']);
            $table->dropIndex(['user_connections']);
            $table->dropIndex(['is_active', 'user_connections']);
            
            $table->dropColumn([
                'is_trusted',
                'trust_level', 
                'mentioned_by',
                'user_connections'
            ]);
        });
    }
};
