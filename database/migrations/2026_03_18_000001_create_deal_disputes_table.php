<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Stores dispute lifecycle state separately from chat messages.
     */
    public function up(): void
    {
        Schema::create('deal_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exchange_lot_id')->constrained('exchange_lots')->cascadeOnDelete();
            $table->string('opened_by', 20)->comment('buyer|seller|system');
            $table->string('status', 20)->default('opened')->comment('opened|in_review|resolved|closed');
            $table->foreignId('opened_message_id')->nullable()->constrained('deal_chat_messages')->nullOnDelete();
            $table->string('reason_code', 100)->nullable();
            $table->string('resolved_by', 20)->nullable()->comment('buyer|seller|arbiter_ai|human_admin');
            $table->text('resolution')->nullable();
            $table->string('arbiter_id', 64)->nullable();
            $table->string('arbiter_model_version', 64)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });

        Schema::table('deal_disputes', function (Blueprint $table) {
            $table->index(['exchange_lot_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deal_disputes');
    }
};
