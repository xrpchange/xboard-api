<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * main_group — группа для production (is_test=false), аналог test_group для sandbox.
     */
    public function up(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->string('main_group', 50)->nullable()->after('test_group');
            $table->index(['is_test', 'main_group']);
        });
    }

    public function down(): void
    {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropIndex(['is_test', 'main_group']);
            $table->dropColumn('main_group');
        });
    }
};
