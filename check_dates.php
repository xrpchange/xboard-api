<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$nodes = DB::table('nodes')
    ->select('id', 'name', 'last_health_check', 'created_at', 'updated_at')
    ->limit(5)
    ->get();

echo json_encode($nodes, JSON_PRETTY_PRINT);

