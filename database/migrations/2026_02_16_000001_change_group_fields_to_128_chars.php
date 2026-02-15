<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE exchange_lots MODIFY test_group VARCHAR(128) NULL');
            DB::statement('ALTER TABLE exchange_lots MODIFY main_group VARCHAR(128) NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE exchange_lots ALTER COLUMN test_group TYPE VARCHAR(128)');
            DB::statement('ALTER TABLE exchange_lots ALTER COLUMN main_group TYPE VARCHAR(128)');
        } elseif ($driver === 'sqlite') {
            // SQLite doesn't support MODIFY; would need full table recreate - skip for sqlite
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE exchange_lots MODIFY test_group VARCHAR(50) NULL');
            DB::statement('ALTER TABLE exchange_lots MODIFY main_group VARCHAR(50) NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE exchange_lots ALTER COLUMN test_group TYPE VARCHAR(50)');
            DB::statement('ALTER TABLE exchange_lots ALTER COLUMN main_group TYPE VARCHAR(50)');
        }
    }
};
