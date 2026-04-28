<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_file_checksums', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exchange_lot_id')->constrained('exchange_lots')->cascadeOnDelete();
            $table->string('sender_side', 20);
            $table->string('tag_type', 50);
            $table->string('sha256', 64);
            $table->timestamps();

            $table->unique(['exchange_lot_id', 'sender_side', 'tag_type', 'sha256'], 'uq_receipt_checksum_scope');
            $table->index(['created_at'], 'idx_receipt_checksums_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_file_checksums');
    }
};
