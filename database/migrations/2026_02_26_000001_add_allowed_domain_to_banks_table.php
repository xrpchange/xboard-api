<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Second-level domain for DKIM/payment email sender whitelist (e.g. sberbank.ru, tinkoff.ru).
     */
    public function up(): void
    {
        Schema::table('banks', function (Blueprint $table) {
            $table->string('allowed_domain', 253)->nullable()->after('is_active')
                ->comment('Second-level domain allowed for payment email sender (DKIM whitelist)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('banks', function (Blueprint $table) {
            $table->dropColumn('allowed_domain');
        });
    }
};
