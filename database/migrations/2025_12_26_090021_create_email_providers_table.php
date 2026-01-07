<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('Provider name (e.g., Gmail, Yandex)');
            $table->string('imap_host', 255)->nullable()->comment('IMAP server hostname');
            $table->integer('imap_port')->default(993)->comment('IMAP server port');
            $table->string('pop3_host', 255)->nullable()->comment('POP3 server hostname');
            $table->integer('pop3_port')->default(995)->comment('POP3 server port');
            $table->boolean('use_ssl')->default(true)->comment('Use SSL/TLS encryption');
            $table->boolean('is_active')->default(true)->comment('Provider is active and available');
            $table->integer('sort_order')->default(0)->comment('Sort order for display');
            $table->timestamps();
            
            $table->index('is_active', 'idx_email_providers_is_active');
            $table->index('sort_order', 'idx_email_providers_sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_providers');
    }
};
