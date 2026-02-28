<?php

/**
 * Test IMAP login to lot mailbox (mail.xdexboard.com:993).
 * Uses webklex/php-imap. Run from project root: php temp/test_lot_mailbox_imap_login.php
 *
 * Credentials (our lot mailbox):
 * - Email: usdt_tron_tdjputgwmqx6mcfd5ax9yerzkyca2hpltm@xdexboard.com
 * - Password: (token hash)
 * - Host: mail.xdexboard.com, Port: 993, SSL: yes
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$email = 'usdt_tron_tdjputgwmqx6mcfd5ax9yerzkyca2hpltm@xdexboard.com';
$password = '45d1d9e7b9d889b20453b8989afb6f2843ddf301f755243afffc9d856fbf1f71';
$host = config('services.lot_mailbox.imap_host', 'mail.xdexboard.com');
$port = (int) config('services.lot_mailbox.imap_port', 993);
$encryption = config('services.lot_mailbox.imap_ssl', true) ? 'ssl' : false;

echo "=== Lot mailbox IMAP login test ===\n";
echo "Host: $host:$port\n";
echo "User: $email\n";
echo "Encryption: " . ($encryption ?: 'none') . "\n\n";

try {
    $cm = new \Webklex\PHPIMAP\ClientManager();
    $client = $cm->make([
        'host' => $host,
        'port' => $port,
        'encryption' => $encryption ?: false,
        'validate_cert' => true,
        'username' => $email,
        'password' => $password,
        'protocol' => 'imap',
    ]);

    $client->connect();
    echo "OK: Connected and authenticated.\n";

    $folders = $client->getFolders();
    echo "Folders: " . $folders->count() . "\n";
    foreach ($folders as $folder) {
        echo "  - " . $folder->full_name . "\n";
    }

    $client->disconnect();
    echo "\nDone.\n";
    exit(0);
} catch (\Webklex\PHPIMAP\Exceptions\ConnectionFailedException $e) {
    echo "FAIL: Connection failed - " . $e->getMessage() . "\n";
    exit(1);
} catch (\Webklex\PHPIMAP\Exceptions\AuthFailedException $e) {
    echo "FAIL: Auth failed - " . $e->getMessage() . "\n";
    exit(1);
} catch (\Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
