<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AML score: integer 0-100 (percentage). Max risk threshold per dispute.
     */
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->unsignedSmallInteger('aml')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->decimal('aml', 10, 2)->unsigned()->nullable()->change();
        });
    }
};
