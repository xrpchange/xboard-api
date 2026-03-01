<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Domain(s) for rate-limit validation: only emails from these domains are allowed for token creation.
     */
    public function up(): void
    {
        Schema::table('email_providers', function (Blueprint $table) {
            $table->string('domain', 255)->nullable()->after('name')->comment('Primary domain, e.g. gmail.com');
        });
    }

    public function down(): void
    {
        Schema::table('email_providers', function (Blueprint $table) {
            $table->dropColumn('domain');
        });
    }
};
