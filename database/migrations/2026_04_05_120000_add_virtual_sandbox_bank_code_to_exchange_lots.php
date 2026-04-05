<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->string('virtual_sandbox_bank_code', 64)->nullable()->after('bank_id')
                ->comment('Sandbox-only synthetic bank; banks.id not used (bank_id stays null)');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropColumn('virtual_sandbox_bank_code');
        });
    }
};
