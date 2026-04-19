<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table): void {
            $table->unsignedInteger('arbiter_ai_invocations')->default(0)->comment('AI arbiter POST /arbiter-message attempts reserved before LLM (anti-budget-abuse)');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("
                UPDATE exchange_lots el
                INNER JOIN (
                    SELECT exchange_lot_id, COUNT(*) AS cnt
                    FROM deal_chat_messages
                    WHERE sender_side = 'arbiter_ai'
                    GROUP BY exchange_lot_id
                ) m ON m.exchange_lot_id = el.id
                SET el.arbiter_ai_invocations = m.cnt
            ");
        }
    }

    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table): void {
            $table->dropColumn('arbiter_ai_invocations');
        });
    }
};
