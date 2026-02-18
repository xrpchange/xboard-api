<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AML score: max risk threshold seller/buyer guarantees per transaction in case of dispute.
     * 0 = ideal, higher = more risk. Nullable — for XMR and other cryptos where risks don't apply.
     */
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->decimal('aml', 10, 2)->unsigned()->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropColumn('aml');
        });
    }
};
