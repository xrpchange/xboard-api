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

// Debug: env loading (remove after fixing)
error_log(sprintf('[xboard-api] APP_ENV: host=%s appEnv=%s envFile=%s server_app_env=%s redirect_app_env=%s env_app_env=%s getenv=%s file_exists=.env=%s .env.xrpchange=%s',
    $_SERVER['HTTP_HOST'] ?? '?',
    $appEnv ?? 'null',
    $envFile ?? 'null',
    $_SERVER['APP_ENV'] ?? 'unset',
    $_SERVER['REDIRECT_APP_ENV'] ?? 'unset',
    $_ENV['APP_ENV'] ?? 'unset',
    getenv('APP_ENV') ?: 'false',
    file_exists(__DIR__ . '/../.env') ? 'yes' : 'no',
    file_exists(__DIR__ . '/../.env.xrpchange') ? 'yes' : 'no'
));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

if ($envFile && file_exists(__DIR__.'/../'.$envFile)) {
    error_log(sprintf('[xboard-api] Loading env from %s', $envFile));
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
} else {
    error_log(sprintf('[xboard-api] Using default .env (envFile=%s exists=%s)', $envFile ?? 'null', $envFile ? (file_exists(__DIR__.'/../'.$envFile) ? 'yes' : 'no') : 'n/a'));
}

$app->handleRequest(Request::capture());
