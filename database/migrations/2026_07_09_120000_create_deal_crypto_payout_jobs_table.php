<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_crypto_payout_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('exchange_lot_id');
            $table->unsignedBigInteger('transaction_id')->unique();
            $table->text('seller_escrow_token_public');
            $table->string('crypto_type', 32);
            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(288);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error', 512)->nullable();
            $table->string('crypto_tx_id', 256)->nullable();
            $table->boolean('aml_required')->default(false);
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
            $table->index('exchange_lot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_crypto_payout_jobs');
    }
};
