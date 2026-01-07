<?php

/**
 * Test script for Yandex IMAP/POP3 connection testing
 *
 * Usage: php test_yandex_imap_pop3.php <protocol> <email> <password>
 *
 * Protocol: imap or pop3
 * Email: your@yandex.ru
 * Password: your password (use app password if 2FA enabled)
 *
 * Examples:
 * php test_yandex_imap_pop3.php imap user@yandex.ru mypassword
 * php test_yandex_imap_pop3.php pop3 user@yandex.ru mypassword
 */

require __DIR__ . '/../vendor/autoload.php';

$protocol = $argv[1] ?? null;
$email = $argv[2] ?? null;
$password = $argv[3] ?? null;

// Handle --force flag
if ($email === '--force') {
    $email = $argv[3] ?? null;
    $password = $argv[4] ?? null;
}

if (!$protocol || !$email || !$password) {
    echo "Usage: php test_yandex_imap_pop3.php <protocol> <email> <password>\n";
    echo "Protocol: imap or pop3\n";
    echo "Email: your@yandex.ru\n";
    echo "Password: your password (use app password if 2FA enabled)\n";
    echo "\nExamples:\n";
    echo "php test_yandex_imap_pop3.php imap user@yandex.ru mypassword\n";
    echo "php test_yandex_imap_pop3.php pop3 user@yandex.ru mypassword\n";
    echo "\nYandex Settings:\n";
    echo "• Enable IMAP/POP3: https://mail.yandex.ru/settings/\n";
    echo "• App password: https://passport.yandex.ru/profile/access (if 2FA enabled)\n";
    echo "• Test with regular password first, if fails - try app password\n";
    exit(1);
}

if (!in_array($protocol, ['imap', 'pop3'])) {
    echo "Error: Protocol must be 'imap' or 'pop3'\n";
    exit(1);
}

// For Yandex, use POP3 by default since IMAP may not be enabled
// But allow forcing IMAP with --force flag
$forceImap = in_array('--force', $argv);
if (!$forceImap && str_contains(strtolower($email), 'yandex.') && $protocol === 'imap') {
    $protocol = 'pop3'; // Auto-switch to POP3 for Yandex
    echo "Auto-switched to POP3 for Yandex (IMAP may not be enabled by default)\n\n";
}

echo "Testing Yandex $protocol connection\n";
echo "Email: $email\n";
echo "Protocol: $protocol\n";
echo "Password: " . str_repeat('*', strlen($password)) . "\n\n";

try {
    $cm = new \Webklex\PHPIMAP\ClientManager();

    if ($protocol === 'imap') {
        $config = [
            'host' => 'imap.yandex.com',
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'protocol' => 'imap',
            'username' => $email,
            'password' => $password,
            'authentication' => 'password',
        ];
    } else { // pop3
        $config = [
            'host' => 'pop.yandex.com',
            'port' => 995,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'protocol' => 'pop3',
            'username' => $email,
            'password' => $password,
            'authentication' => 'password',
        ];
    }

    echo "=== Configuration ===\n";
    echo "Host: {$config['host']}:{$config['port']}\n";
    echo "Encryption: {$config['encryption']}\n";
    echo "Protocol: {$config['protocol']}\n";
    echo "Username: {$config['username']}\n";
    echo "Authentication: {$config['authentication']}\n\n";

    echo "=== Connecting ===\n";
    $client = $cm->make($config);

    $startTime = microtime(true);
    $client->connect();
    $connectTime = microtime(true) - $startTime;

    echo "✓ Connection successful! ({$connectTime}s)\n";

    if ($protocol === 'imap') {
        echo "\n=== Testing IMAP ===\n";

        // Test getting folders
        $folders = $client->getFolders();
        echo "✓ Found " . count($folders) . " folders\n";

        // Test accessing INBOX
        $folder = $client->getFolder('INBOX');
        echo "✓ INBOX folder accessed\n";

        // Test getting message count
        $messages = $folder->messages()->all()->get();
        echo "✓ Found " . count($messages) . " messages in INBOX\n";

        // Test getting recent message (if any)
        if (count($messages) > 0) {
            $latestMessage = $messages->first();
            $subject = $latestMessage->getSubject() ?? '(no subject)';
            $from = is_array($latestMessage->getFrom()) && !empty($latestMessage->getFrom())
                ? $latestMessage->getFrom()[0]->mail
                : 'unknown';
            echo "✓ Latest message: '$subject' from $from\n";
        }

    } else { // pop3
        echo "\n=== Testing POP3 ===\n";

        // For POP3, just check if connection works and get message count
        // PHP IMAP library handles POP3 differently
        echo "✓ POP3 connection established\n";

        // Try to get message count (POP3 specific)
        $messages = $client->getFolder('INBOX')->messages()->all()->get();
        echo "✓ Found " . count($messages) . " messages\n";

        if (count($messages) > 0) {
            $latestMessage = $messages->first();
            echo "✓ Can access messages\n";
        }
    }

    $client->disconnect();
    echo "\n✓ Test completed successfully!\n";

    echo "\n=== Troubleshooting Tips ===\n";
    if ($protocol === 'imap') {
        echo "If connection failed:\n";
        echo "1. Enable IMAP in Yandex: https://mail.yandex.ru/settings/\n";
        echo "2. If 2FA enabled, create app password: https://passport.yandex.ru/profile/access\n";
        echo "3. Check firewall/antivirus blocking port 993\n";
        echo "4. Try different email format: login@yandex.ru vs login@yandex.com\n";
    } else {
        echo "If connection failed:\n";
        echo "1. Enable POP3 in Yandex: https://mail.yandex.ru/settings/\n";
        echo "2. If 2FA enabled, create app password: https://passport.yandex.ru/profile/access\n";
        echo "3. Check firewall/antivirus blocking port 995\n";
        echo "4. Try different email format: login@yandex.ru vs login@yandex.com\n";
    }

} catch (\Webklex\PHPIMAP\Exceptions\ConnectionFailedException $e) {
    echo "✗ Connection failed: " . $e->getMessage() . "\n";

    if (strpos($e->getMessage(), 'AUTHENTICATIONFAILED') !== false) {
        echo "\n🔍 AUTHENTICATION FAILED - Common causes:\n";
        echo "• Wrong password or email\n";
        echo "• 2FA enabled - need app password, not regular password\n";
        echo "• IMAP/POP3 disabled in Yandex settings\n";
        echo "• Account blocked/temporarily suspended\n";
    } elseif (strpos($e->getMessage(), 'ssl') !== false) {
        echo "\n🔍 SSL CONNECTION ERROR - Common causes:\n";
        echo "• Firewall/antivirus blocking SSL connections\n";
        echo "• Network restrictions\n";
        echo "• Wrong port (IMAP:993, POP3:995)\n";
    }

} catch (\Exception $e) {
    echo "✗ Test failed: " . $e->getMessage() . "\n";
    echo "Error type: " . get_class($e) . "\n";

    // Additional debugging for common errors
    $errorMsg = strtolower($e->getMessage());
    if (strpos($errorMsg, 'authentication') !== false) {
        echo "\n🔍 Authentication issue detected\n";
    } elseif (strpos($errorMsg, 'connection') !== false) {
        echo "\n🔍 Connection issue detected\n";
    } elseif (strpos($errorMsg, 'ssl') !== false) {
        echo "\n🔍 SSL/TLS issue detected\n";
    }
}

echo "\n=== Yandex Settings Links ===\n";
echo "IMAP/POP3 settings: https://mail.yandex.ru/settings/\n";
echo "App passwords: https://passport.yandex.ru/profile/access\n";
echo "Security settings: https://passport.yandex.ru/profile\n";

exit(0);

