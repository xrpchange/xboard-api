<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "UPDATE exchange_lots SET client_web_origin = LOWER(TRIM(BOTH '/' FROM REPLACE(REPLACE(client_web_origin, 'https://', ''), 'http://', ''))) "
            ."WHERE client_web_origin LIKE 'https://%' OR client_web_origin LIKE 'http://%'"
        );
    }

    public function down(): void
    {
        // Irreversible: prior values were normalized HTTPS origins; re-prefixing may be wrong for dev.* hosts.
    }
};
