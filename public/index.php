<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel first to load default .env
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

// Load environment-specific .env file if APP_ENV is set (after bootstrap to override)
// Apache sets this via SetEnv APP_ENV xrpchange
// Try multiple ways to get APP_ENV (SetEnv can set it in different places)
$appEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? null;

if (!empty($appEnv)) {
    $envFile = __DIR__.'/../.env.' . $appEnv;
    
    if (file_exists($envFile)) {
        try {
            $dotenv = Dotenv\Dotenv::createImmutable(__DIR__.'/..', '.env.' . $appEnv);
            $dotenv->load();
            
            // Reconfigure database connection with new values
            config(['database.connections.mysql.host' => env('DB_HOST')]);
            config(['database.connections.mysql.database' => env('DB_DATABASE')]);
            config(['database.connections.mysql.username' => env('DB_USERNAME')]);
            config(['database.connections.mysql.password' => env('DB_PASSWORD')]);
            config(['database.connections.mysql.port' => env('DB_PORT', '3306')]);
            
            // Also set APP_ENV in config for logging
            config(['app.env' => $appEnv]);
        } catch (\Exception $e) {
            // Silently fail - fall back to default .env
        }
    }
}

$app->handleRequest(Request::capture());
