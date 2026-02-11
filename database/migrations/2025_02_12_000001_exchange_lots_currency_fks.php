<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Замена from_currency/to_currency (string) на FK в справочники:
 * from_fiat_currency_id, from_crypto_currency_id (ровно один задан),
 * to_fiat_currency_id, to_crypto_currency_id (ровно один задан).
 * API по-прежнему принимает и отдаёт коды (маппинг в контроллере/ресурсе).
 */
class ExchangeLotsCurrencyFks extends Migration
{
    public function up(): void
    {
        $hadFromCurrency = Schema::hasColumn('exchange_lots', 'from_currency');

        if ($hadFromCurrency) {
        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->foreignId('from_fiat_currency_id')->nullable()->after('to_currency')->constrained('fiat_currencies');
            $table->foreignId('from_crypto_currency_id')->nullable()->after('from_fiat_currency_id')->constrained('crypto_currencies');
            $table->foreignId('to_fiat_currency_id')->nullable()->after('from_crypto_currency_id')->constrained('fiat_currencies');
            $table->foreignId('to_crypto_currency_id')->nullable()->after('to_fiat_currency_id')->constrained('crypto_currencies');
        });

        $driver = Schema::getConnection()->getDriverName();
        $table = 'exchange_lots';

        foreach (DB::table($table)->get() as $row) {
            $fromCode = $row->from_currency ? trim($row->from_currency) : null;
            $toCode = $row->to_currency ? trim($row->to_currency) : null;
            $fromFiatId = null;
            $fromCryptoId = null;
            $toFiatId = null;
            $toCryptoId = null;

            if ($fromCode !== null && $fromCode !== '') {
                $fiat = DB::table('fiat_currencies')->where('code', strtoupper($fromCode))->first();
                if ($fiat) {
                    $fromFiatId = $fiat->id;
                } else {
                    $crypto = DB::table('crypto_currencies')->whereRaw('LOWER(code) = ?', [strtolower($fromCode)])->first();
                    if ($crypto) {
                        $fromCryptoId = $crypto->id;
                    }
                }
            }
            if ($toCode !== null && $toCode !== '') {
                $fiat = DB::table('fiat_currencies')->where('code', strtoupper($toCode))->first();
                if ($fiat) {
                    $toFiatId = $fiat->id;
                } else {
                    $crypto = DB::table('crypto_currencies')->whereRaw('LOWER(code) = ?', [strtolower($toCode)])->first();
                    if ($crypto) {
                        $toCryptoId = $crypto->id;
                    }
                }
            }

            DB::table($table)->where('id', $row->id)->update([
                'from_fiat_currency_id' => $fromFiatId,
                'from_crypto_currency_id' => $fromCryptoId,
                'to_fiat_currency_id' => $toFiatId,
                'to_crypto_currency_id' => $toCryptoId,
            ]);
        }

        $badFrom = DB::table($table)->whereNull('from_fiat_currency_id')->whereNull('from_crypto_currency_id')->exists();
        $badTo = DB::table($table)->whereNull('to_fiat_currency_id')->whereNull('to_crypto_currency_id')->exists();
        if ($badFrom || $badTo) {
            throw new \RuntimeException(
                'exchange_lots: some rows have unresolved from_currency or to_currency. Add missing codes to fiat_currencies or crypto_currencies and run migration again.'
            );
        }

        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropColumn(['from_currency', 'to_currency']);
        });
        }

        $driver = Schema::getConnection()->getDriverName();

        // MySQL 8: CHECK нельзя вешать на столбцы, участвующие во FK. Сначала снимаем FK, вешаем CHECK, затем FK заново.
        if ($driver === 'mysql' && Schema::hasColumn('exchange_lots', 'from_fiat_currency_id')) {
            Schema::table('exchange_lots', function (Blueprint $table) {
                $table->dropForeign(['from_fiat_currency_id']);
                $table->dropForeign(['from_crypto_currency_id']);
                $table->dropForeign(['to_fiat_currency_id']);
                $table->dropForeign(['to_crypto_currency_id']);
            });
        }

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE exchange_lots ADD CONSTRAINT chk_exchange_lots_from_one CHECK (
                (from_fiat_currency_id IS NOT NULL AND from_crypto_currency_id IS NULL) OR
                (from_fiat_currency_id IS NULL AND from_crypto_currency_id IS NOT NULL)
            )');
            DB::statement('ALTER TABLE exchange_lots ADD CONSTRAINT chk_exchange_lots_to_one CHECK (
                (to_fiat_currency_id IS NOT NULL AND to_crypto_currency_id IS NULL) OR
                (to_fiat_currency_id IS NULL AND to_crypto_currency_id IS NOT NULL)
            )');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE exchange_lots ADD CONSTRAINT chk_exchange_lots_from_one CHECK (
                (from_fiat_currency_id IS NOT NULL AND from_crypto_currency_id IS NULL) OR
                (from_fiat_currency_id IS NULL AND from_crypto_currency_id IS NOT NULL)
            )');
            DB::statement('ALTER TABLE exchange_lots ADD CONSTRAINT chk_exchange_lots_to_one CHECK (
                (to_fiat_currency_id IS NOT NULL AND to_crypto_currency_id IS NULL) OR
                (to_fiat_currency_id IS NULL AND to_crypto_currency_id IS NOT NULL)
            )');
        }

        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->index('from_fiat_currency_id');
            $table->index('from_crypto_currency_id');
            $table->index('to_fiat_currency_id');
            $table->index('to_crypto_currency_id');
        });

        if ($driver === 'mysql') {
            Schema::table('exchange_lots', function (Blueprint $table) {
                $table->foreign('from_fiat_currency_id')->references('id')->on('fiat_currencies');
                $table->foreign('from_crypto_currency_id')->references('id')->on('crypto_currencies');
                $table->foreign('to_fiat_currency_id')->references('id')->on('fiat_currencies');
                $table->foreign('to_crypto_currency_id')->references('id')->on('crypto_currencies');
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE exchange_lots DROP CHECK chk_exchange_lots_from_one');
            DB::statement('ALTER TABLE exchange_lots DROP CHECK chk_exchange_lots_to_one');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE exchange_lots DROP CONSTRAINT IF EXISTS chk_exchange_lots_from_one');
            DB::statement('ALTER TABLE exchange_lots DROP CONSTRAINT IF EXISTS chk_exchange_lots_to_one');
        }

        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropForeign(['from_fiat_currency_id']);
            $table->dropForeign(['from_crypto_currency_id']);
            $table->dropForeign(['to_fiat_currency_id']);
            $table->dropForeign(['to_crypto_currency_id']);
        });

        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->string('from_currency', 20)->nullable()->after('node_id');
            $table->string('to_currency', 20)->nullable()->after('from_currency');
        });

        $table = 'exchange_lots';
        foreach (DB::table($table)->get() as $row) {
            $fromCode = null;
            if ($row->from_fiat_currency_id) {
                $f = DB::table('fiat_currencies')->find($row->from_fiat_currency_id);
                $fromCode = $f?->code ?? '';
            } elseif ($row->from_crypto_currency_id) {
                $c = DB::table('crypto_currencies')->find($row->from_crypto_currency_id);
                $fromCode = $c?->code ?? '';
            }
            $toCode = null;
            if ($row->to_fiat_currency_id) {
                $f = DB::table('fiat_currencies')->find($row->to_fiat_currency_id);
                $toCode = $f?->code ?? '';
            } elseif ($row->to_crypto_currency_id) {
                $c = DB::table('crypto_currencies')->find($row->to_crypto_currency_id);
                $toCode = $c?->code ?? '';
            }
            DB::table($table)->where('id', $row->id)->update([
                'from_currency' => $fromCode,
                'to_currency' => $toCode,
            ]);
        }

        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->string('from_currency', 20)->nullable(false)->change();
            $table->string('to_currency', 20)->nullable(false)->change();
        });

        Schema::table('exchange_lots', function (Blueprint $table) {
            $table->dropColumn([
                'from_fiat_currency_id',
                'from_crypto_currency_id',
                'to_fiat_currency_id',
                'to_crypto_currency_id',
            ]);
        });
    }
}
