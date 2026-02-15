<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine APP_ENV early (Apache: SetEnv APP_ENV xrpchange, or REDIRECT_APP_ENV with mod_rewrite)
$appEnv = $_SERVER['APP_ENV'] ?? $_SERVER['REDIRECT_APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');
if (empty($appEnv) && function_exists('apache_getenv')) {
    $appEnv = apache_getenv('APP_ENV') ?: apache_getenv('REDIRECT_APP_ENV');
}
$envFile = !empty($appEnv) ? '.env.' . $appEnv : null;

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

if ($envFile && file_exists(__DIR__.'/../'.$envFile)) {
    $app->loadEnvironmentFrom($envFile);

    // PHP-FPM workers reuse $_ENV between requests. Dotenv::safeLoad() does NOT overwrite
    // existing vars, so a previous xdexboard request leaves DB_DATABASE=xboard. We must clear
    // DB vars before LoadEnvironmentVariables runs so .env.xrpchange can set them.
    $app->beforeBootstrapping(LoadEnvironmentVariables::class, function () {
        $dbVars = ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SOCKET', 'DB_CHARSET', 'DB_COLLATION'];
        foreach ($dbVars as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    });
}

$app->handleRequest(Request::capture());
