<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AML in exchange_lots is a flag:
     * - true  => clean-wallet check is required for this lot
     * - false => no clean-wallet requirement
     * - null  => unspecified (legacy rows)
     */
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->boolean('aml')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->unsignedSmallInteger('aml')->nullable()->change();
        });
    }
};

