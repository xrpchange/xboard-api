<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_test_counters', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255);
            // Calendar day in server timezone; we don't need full timestamp granularity here.
            $table->date('day');
            $table->unsignedInteger('sent_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();

            $table->unique(['email', 'day'], 'email_test_counters_email_day_unique');
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_test_counters');
    }
};

