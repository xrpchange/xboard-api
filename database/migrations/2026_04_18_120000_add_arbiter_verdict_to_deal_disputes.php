<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_disputes', function (Blueprint $table) {
            $table->string('arbiter_verdict', 32)->nullable()->after('arbiter_model_version')
                ->comment('manual_review|favor_buyer|favor_seller|continue_review from AI arbiter JSON line');
        });
    }

    public function down(): void
    {
        Schema::table('deal_disputes', function (Blueprint $table) {
            $table->dropColumn('arbiter_verdict');
        });
    }
};
