<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Rate limiting for escrow token creation: per-email (monthly) and per-IP (daily).
     */
    public function up(): void
    {
        Schema::create('token_creation_quota', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255)->comment('Normalized email (lowercase)');
            $table->string('period_month', 7)->comment('YYYY-MM');
            $table->string('environment', 20)->comment('sandbox or production');
            $table->unsignedInteger('count')->default(0)->comment('Tokens created in this period');
            $table->timestamps();

            $table->unique(['email', 'period_month', 'environment'], 'uq_token_creation_quota');
            $table->index('period_month');
        });

        Schema::create('token_creation_ip_daily', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45)->comment('IPv4 or IPv6');
            $table->date('date')->comment('Day (UTC)');
            $table->unsignedInteger('count')->default(0)->comment('Tokens created from this IP on this day');
            $table->timestamps();

            $table->unique(['ip_address', 'date'], 'uq_token_creation_ip_daily');
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_creation_quota');
        Schema::dropIfExists('token_creation_ip_daily');
    }
};
