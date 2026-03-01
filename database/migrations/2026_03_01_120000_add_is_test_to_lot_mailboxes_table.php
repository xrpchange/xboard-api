<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add is_test to lot_mailboxes: true = tied to test/sandbox lot or token.
     * Filled from APP_ENV at create time (Laravel knows mode at startup); not passed from client.
     */
    public function up(): void
    {
        Schema::table('lot_mailboxes', function (Blueprint $table) {
            $table->boolean('is_test')->default(true)->after('currency')
                ->comment('true if mailbox is for test/sandbox lot; set from APP_ENV when creating');
        });
    }

    public function down(): void
    {
        Schema::table('lot_mailboxes', function (Blueprint $table) {
            $table->dropColumn('is_test');
        });
    }
};
