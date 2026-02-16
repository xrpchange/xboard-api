<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Store test_group and main_group as SHA256 hash (64 hex chars) instead of plain text.
 * Migrates existing plain-text values to hash before shrinking column size.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // Hash existing plain-text values before altering column size
        if ($driver === 'mysql') {
            DB::statement("UPDATE exchange_lots SET test_group = LOWER(SHA2(test_group, 256)) WHERE test_group IS NOT NULL AND test_group != '' AND LENGTH(test_group) != 64");
            DB::statement("UPDATE exchange_lots SET main_group = LOWER(SHA2(main_group, 256)) WHERE main_group IS NOT NULL AND main_group != '' AND LENGTH(main_group) != 64");
        } elseif ($driver === 'pgsql') {
            DB::statement("UPDATE exchange_lots SET test_group = encode(sha256(convert_to(test_group, 'UTF8')), 'hex') WHERE test_group IS NOT NULL AND test_group != '' AND LENGTH(test_group) != 64");
            DB::statement("UPDATE exchange_lots SET main_group = encode(sha256(convert_to(main_group, 'UTF8')), 'hex') WHERE main_group IS NOT NULL AND main_group != '' AND LENGTH(main_group) != 64");
        }

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE exchange_lots MODIFY test_group VARCHAR(64) NULL');
            DB::statement('ALTER TABLE exchange_lots MODIFY main_group VARCHAR(64) NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE exchange_lots ALTER COLUMN test_group TYPE VARCHAR(64)');
            DB::statement('ALTER TABLE exchange_lots ALTER COLUMN main_group TYPE VARCHAR(64)');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        // Cannot reverse hash — restore to 128 chars for compatibility
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE exchange_lots MODIFY test_group VARCHAR(128) NULL');
            DB::statement('ALTER TABLE exchange_lots MODIFY main_group VARCHAR(128) NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE exchange_lots ALTER COLUMN test_group TYPE VARCHAR(128)');
            DB::statement('ALTER TABLE exchange_lots ALTER COLUMN main_group TYPE VARCHAR(128)');
        }
    }
};
