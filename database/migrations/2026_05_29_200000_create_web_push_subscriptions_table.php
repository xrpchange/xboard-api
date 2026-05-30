<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('client_id', 64);
            $table->string('endpoint', 512);
            $table->string('public_key', 255);
            $table->string('auth_token', 64);
            $table->string('content_encoding', 16)->default('aesgcm');
            $table->unsignedBigInteger('exchange_lot_id');
            $table->string('recipient_role', 16);
            $table->string('node_key', 128);
            $table->string('token_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['endpoint', 'exchange_lot_id', 'recipient_role'], 'web_push_endpoint_lot_role_unique');
            $table->index(['exchange_lot_id', 'recipient_role'], 'web_push_lot_role_idx');
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_subscriptions');
    }
};
