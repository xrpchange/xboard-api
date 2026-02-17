<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mailboxes for lot chat (IMAP).
     * Username = local_part (e.g. xmr_48abc...), domain = xdexboard.com.
     * Password = token_hash from escrow token (stored hashed for Dovecot).
     */
    public function up(): void
    {
        Schema::create('lot_mailboxes', function (Blueprint $table) {
            $table->id();
            $table->string('local_part', 255)->unique()->comment('currency_normalized_address, e.g. xmr_48abc123...');
            $table->string('domain', 64)->default('xdexboard.com');
            $table->string('password_hash', 255)->comment('bcrypt of token_hash for Dovecot auth');
            $table->string('currency', 32)->nullable()->comment('from escrow token');
            $table->timestamps();

            $table->index(['domain', 'local_part']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_mailboxes');
    }
};
