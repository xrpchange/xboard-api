<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->string('test_group', 128)->nullable()->change();
            $table->string('main_group', 128)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->string('test_group', 50)->nullable()->change();
            $table->string('main_group', 50)->nullable()->change();
        });
    }
};
