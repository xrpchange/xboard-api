<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * crypto_currencies is not configuration—it is a mirror of Core data (GET /info).
     * This migration refactors an existing table to match that schema: drop is_stablecoin,
     * add is_native and network, backfill by code. Idempotent when the table already has the new schema.
     */
    public function up(): void
    {
        if (!Schema::hasTable('crypto_currencies')) {
            return;
        }

        // 1) Drop old column
        if (Schema::hasColumn('crypto_currencies', 'is_stablecoin')) {
            Schema::table('crypto_currencies', function (Blueprint $table) {
                $table->dropColumn('is_stablecoin');
            });
        }

        // 2) Add new columns (and indexes) only if missing — idempotent when table already has new schema
        $addNative = !Schema::hasColumn('crypto_currencies', 'is_native');
        $addNetwork = !Schema::hasColumn('crypto_currencies', 'network');

        if ($addNative || $addNetwork) {
            Schema::table('crypto_currencies', function (Blueprint $table) use ($addNative, $addNetwork) {
                if ($addNative) {
                    $table->boolean('is_native')->default(false)->after('name')
                        ->comment('True = native chain coin (xrp, btc, xmr), false = token on network');
                }
                if ($addNetwork) {
                    $after = $addNative ? 'is_native' : 'name';
                    $table->string('network', 20)->nullable()->after($after)
                        ->comment('Network from Core: null for native coin, stellar|polygon|tron for tokens');
                }
            });
            Schema::table('crypto_currencies', function (Blueprint $table) use ($addNative, $addNetwork) {
                if ($addNative) {
                    $table->index('is_native');
                }
                if ($addNetwork) {
                    $table->index('network');
                }
            });
        }

        // 4) Widen code from 10 to 32 if needed (old migration used 10)
        if (Schema::hasColumn('crypto_currencies', 'code')) {
            $connection = Schema::getConnection();
            $driver = $connection->getDriverName();
            try {
                if ($driver === 'mysql') {
                    $connection->statement('ALTER TABLE crypto_currencies MODIFY code VARCHAR(32) NOT NULL');
                } elseif ($driver === 'pgsql') {
                    $connection->statement('ALTER TABLE crypto_currencies ALTER COLUMN code TYPE VARCHAR(32)');
                }
            } catch (\Throwable $e) {
                // Ignore if already 32 or unsupported
            }
        }

        // 5) Backfill network and is_native by code: native coin => network null; token => network stellar|polygon|tron
        $map = [
            'xrp' => ['network' => null, 'is_native' => true],
            'btc' => ['network' => null, 'is_native' => true],
            'xmr' => ['network' => null, 'is_native' => true],
            'usdt_stellar' => ['network' => 'stellar', 'is_native' => false],
            'usdc_stellar' => ['network' => 'stellar', 'is_native' => false],
            'usdt_polygon' => ['network' => 'polygon', 'is_native' => false],
            'usdc_polygon' => ['network' => 'polygon', 'is_native' => false],
            'dai_polygon' => ['network' => 'polygon', 'is_native' => false],
            'usdt_tron' => ['network' => 'tron', 'is_native' => false],
            'usdc_tron' => ['network' => 'tron', 'is_native' => false],
        ];
        $driver = DB::getDriverName();
        foreach ($map as $code => $values) {
            $codeLower = strtolower($code);
            $update = [
                'network' => $values['network'],
                'is_native' => $driver === 'mysql' ? ($values['is_native'] ? 1 : 0) : $values['is_native'],
            ];
            DB::table('crypto_currencies')->whereRaw('LOWER(code) = ?', [$codeLower])->update($update);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('crypto_currencies')) {
            return;
        }

        Schema::table('crypto_currencies', function (Blueprint $table) {
            if (Schema::hasColumn('crypto_currencies', 'is_native')) {
                $table->dropIndex(['is_native']);
                $table->dropColumn('is_native');
            }
            if (Schema::hasColumn('crypto_currencies', 'network')) {
                $table->dropIndex(['network']);
                $table->dropColumn('network');
            }
        });

        if (!Schema::hasColumn('crypto_currencies', 'is_stablecoin')) {
            Schema::table('crypto_currencies', function (Blueprint $table) {
                $table->boolean('is_stablecoin')->default(false)->after('name');
            });
        }
    }
};
