<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->boolean('is_training')->default(false)->after('is_test');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropColumn('is_training');
        });
    }
};
