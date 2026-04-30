<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link nodes.country_code to countries.code (ISO 3166-1 alpha-3).
     * country_code remains nullable; invalid/orphan codes are cleared before the FK is added.
     */
    public function up(): void
    {
        DB::table('nodes')
            ->whereNotNull('country_code')
            ->update(['country_code' => DB::raw('UPPER(TRIM(country_code))')]);

        DB::statement('
            UPDATE nodes n
            LEFT JOIN countries c ON n.country_code = c.code
            SET n.country_code = NULL
            WHERE n.country_code IS NOT NULL AND c.id IS NULL
        ');

        Schema::table('nodes', function (Blueprint $table) {
            $table->dropIndex(['country_code']);
        });

        Schema::table('nodes', function (Blueprint $table) {
            $table->foreign('country_code')
                ->references('code')
                ->on('countries')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropForeign(['country_code']);
        });

        Schema::table('nodes', function (Blueprint $table) {
            $table->index('country_code');
        });
    }
};
