<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per deal-mailbox daily send counter (UTC calendar day).
     * Operator can block a participant for the rest of the day by setting sent_count to the limit.
     */
    public function up(): void
    {
        Schema::create('deal_internal_mail_daily_counters', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255)->comment('Sender lot mailbox (normalized lowercase)');
            $table->date('day')->comment('UTC calendar day');
            $table->unsignedInteger('sent_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['email', 'day'], 'deal_internal_mail_daily_email_day_unique');
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_internal_mail_daily_counters');
    }
};
