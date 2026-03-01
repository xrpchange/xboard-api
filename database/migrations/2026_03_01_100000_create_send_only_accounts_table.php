<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Send-only SMTP accounts (e.g. noreply@) — not for IMAP/chat.
     * Used by Dovecot for SASL; credentials also in .env for Laravel Mail.
     */
    public function up(): void
    {
        Schema::create('send_only_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('local_part', 255)->comment('e.g. noreply');
            $table->string('domain', 64)->default('xdexboard.com');
            $table->string('password_hash', 255)->comment('bcrypt for Dovecot SASL');
            $table->timestamps();

            $table->unique(['local_part', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('send_only_accounts');
    }
};
