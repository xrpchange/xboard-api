<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Stores deal chat messages; written when API sends deal email (mail as event).
     * Client shows dialog from this table and syncs on open; IMAP can be used to push received messages later.
     */
    public function up(): void
    {
        Schema::create('deal_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exchange_lot_id')->constrained('exchange_lots')->cascadeOnDelete();
            /** Who sent: buyer, seller, or system (noreply) */
            $table->string('sender_side', 20)->comment('buyer|seller|system');
            $table->string('from_email', 255)->nullable();
            $table->string('to_email', 255)->nullable();
            $table->text('body');
            /** X-XBoard-Deal-Tag header value (JSON), e.g. start_deal, buyer_exited, payment_details */
            $table->text('deal_tag')->nullable();
            $table->timestamps();
            /** When mailbox is cleared, messages are soft-deleted (not removed). */
            $table->softDeletes();
        });

        Schema::table('deal_chat_messages', function (Blueprint $table) {
            $table->index(['exchange_lot_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deal_chat_messages');
    }
};
