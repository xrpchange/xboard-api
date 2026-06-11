<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_external_email_reputations', function (Blueprint $table) {
            $table->string('external_email', 255)->primary();
            $table->unsignedInteger('completed_deals_count')->default(0);
            $table->unsignedInteger('bad_deals_count')->default(0);
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'buyer_notification_email')) {
                $table->string('buyer_notification_email', 255)
                    ->nullable()
                    ->after('buyer_lot_email')
                    ->comment('Buyer external notification email snapshot at sale row creation');
            }
        });

        $this->backfillFromHistory();
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'buyer_notification_email')) {
                $table->dropColumn('buyer_notification_email');
            }
        });

        Schema::dropIfExists('buyer_external_email_reputations');
    }

    private function backfillFromHistory(): void
    {
        if (! Schema::hasTable('transactions') || ! Schema::hasTable('exchange_lots')) {
            return;
        }

        DB::statement("
            INSERT INTO buyer_external_email_reputations (external_email, completed_deals_count, bad_deals_count, created_at, updated_at)
            SELECT
                LOWER(TRIM(email_src.external_email)) AS external_email,
                COALESCE(completed.cnt, 0) AS completed_deals_count,
                COALESCE(bad.cnt, 0) AS bad_deals_count,
                UTC_TIMESTAMP(),
                UTC_TIMESTAMP()
            FROM (
                SELECT DISTINCT LOWER(TRIM(el.buyer_notification_email)) AS external_email
                FROM exchange_lots el
                WHERE el.buyer_notification_email IS NOT NULL
                  AND TRIM(el.buyer_notification_email) <> ''
                UNION
                SELECT DISTINCT LOWER(TRIM(t.buyer_notification_email)) AS external_email
                FROM transactions t
                WHERE t.buyer_notification_email IS NOT NULL
                  AND TRIM(t.buyer_notification_email) <> ''
            ) AS email_src
            LEFT JOIN (
                SELECT LOWER(TRIM(COALESCE(t.buyer_notification_email, el.buyer_notification_email))) AS external_email,
                       COUNT(*) AS cnt
                FROM transactions t
                INNER JOIN exchange_lots el ON el.id = t.exchange_lot_id
                WHERE t.tx_type = 'sale'
                  AND t.status = 'completed'
                  AND COALESCE(t.buyer_notification_email, el.buyer_notification_email) IS NOT NULL
                  AND TRIM(COALESCE(t.buyer_notification_email, el.buyer_notification_email)) <> ''
                GROUP BY LOWER(TRIM(COALESCE(t.buyer_notification_email, el.buyer_notification_email)))
            ) AS completed ON completed.external_email = email_src.external_email
            LEFT JOIN (
                SELECT LOWER(TRIM(el.buyer_notification_email)) AS external_email,
                       COUNT(DISTINCT el.id) AS cnt
                FROM deal_disputes dd
                INNER JOIN exchange_lots el ON el.id = dd.exchange_lot_id
                WHERE dd.status = 'closed'
                  AND dd.arbiter_verdict = 'favor_seller'
                  AND el.buyer_notification_email IS NOT NULL
                  AND TRIM(el.buyer_notification_email) <> ''
                GROUP BY LOWER(TRIM(el.buyer_notification_email))
            ) AS bad ON bad.external_email = email_src.external_email
            WHERE email_src.external_email IS NOT NULL
              AND email_src.external_email <> ''
            ON DUPLICATE KEY UPDATE
                completed_deals_count = VALUES(completed_deals_count),
                bad_deals_count = VALUES(bad_deals_count),
                updated_at = VALUES(updated_at)
        ");

        DB::statement("
            UPDATE transactions t
            INNER JOIN exchange_lots el ON el.id = t.exchange_lot_id
            SET t.buyer_notification_email = el.buyer_notification_email
            WHERE t.tx_type = 'sale'
              AND t.buyer_notification_email IS NULL
              AND el.buyer_notification_email IS NOT NULL
              AND TRIM(el.buyer_notification_email) <> ''
        ");
    }
};
