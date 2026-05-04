<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL: extend tx_type with internal pre-payment row holding buyer IP + consent for the active deal.
        DB::statement("ALTER TABLE transactions MODIFY COLUMN tx_type ENUM(
            'deposit','withdrawal','fee','refund','compensation','sale','deal_session'
        ) NOT NULL COMMENT 'Transaction type'");

        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('buyer_seen_ip', 45)->nullable()->after('buyer_lot_email');
            $table->boolean('buyer_ip_share_with_seller')->default(false)->after('buyer_seen_ip');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn(['buyer_seen_ip', 'buyer_ip_share_with_seller']);
        });

        DB::statement("ALTER TABLE transactions MODIFY COLUMN tx_type ENUM(
            'deposit','withdrawal','fee','refund','compensation','sale'
        ) NOT NULL COMMENT 'Transaction type'");
    }
};
