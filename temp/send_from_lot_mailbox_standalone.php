<?php
/**
 * Standalone script: send one email FROM the lot mailbox (SMTP test).
 * Copy to document root of game1-db.xrpchange.site and run:
 *   php send_from_lot_mailbox_standalone.php
 *
 * From: usdt_tron_...@xdexboard.com  To: tehnorgany@yandex.ru
 * SMTP: mail.xdexboard.com:587 (STARTTLS)
 */
$fromEmail = 'usdt_tron_tdjputgwmqx6mcfd5ax9yerzkyca2hpltm@xdexboard.com';
$password = '45d1d9e7b9d889b20453b8989afb6f2843ddf301f755243afffc9d856fbf1f71';
$toEmail = 'tehnorgany@yandex.ru';
$smtpHost = 'mail.xdexboard.com';
$smtpPort = 587;
$verbose = (php_sapi_name() === 'cli' && in_array('-v', $argv ?? [], true));

$body = "This is a test email sent from the lot mailbox via SMTP (mail.xdexboard.com).\r\n\r\nIf you received this, SMTP for the lot chat mailbox is working.";
$subject = 'SMTP test from lot mailbox';
$message = "Subject: $subject\r\nFrom: $fromEmail\r\nTo: $toEmail\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$body";

function smtpLine($fp, $cmd, $verbose) {
    fwrite($fp, $cmd . "\r\n");
    $r = fgets($fp, 512);
    if ($verbose && $r !== false) {
        echo "  <- " . trim($r) . "\n";
    }
    return $r;
}
function smtpReadToEnd($fp, $verbose) {
    while (($line = fgets($fp, 512)) !== false) {
        if ($verbose) echo "  <- " . trim($line) . "\n";
        if (strlen($line) >= 4 && substr($line, 3, 1) === ' ') {
            return $line;
        }
    }
    return false;
}

// Try 127.0.0.1 first (verify_peer off for local), then hostname
$tryHosts = ($smtpHost === '127.0.0.1') ? ['127.0.0.1'] : ['127.0.0.1', $smtpHost];
$fp = null;
$response = null;
$lastErr = '';
$isLocal = false;
foreach ($tryHosts as $host) {
    $errNo = 0;
    $errStr = '';
    // For 127.0.0.1: peer_name sets SNI so server sends the right cert; verification off for local
    $ctx = ($host === '127.0.0.1')
        ? stream_context_create(['ssl' => [
            'peer_name' => $smtpHost,
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]])
        : stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client("tcp://$host:$smtpPort", $errNo, $errStr, 5, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $lastErr = " $host:$smtpPort: " . ($errStr ?: 'failed') . " ($errNo)";
        if ($verbose) echo "  $host:$smtpPort: $errStr ($errNo)\n";
        continue;
    }
    $isLocal = ($host === '127.0.0.1');
    stream_set_timeout($fp, 10);
    $response = @fgets($fp, 512);
    if ($response !== false && $response !== '' && strpos(trim($response), '220') === 0) {
        if ($verbose && $host !== $smtpHost) echo "Using $host (local)\n";
        break;
    }
    if ($verbose && $response !== false) echo "  $host: no 220, got: " . trim($response ?? '') . "\n";
    fclose($fp);
    $fp = null;
    $response = null;
    $lastErr = " $host: no 220 banner";
}
if (!$fp) {
    echo "FAIL: Connect" . ($lastErr ?: ' both hosts failed') . "\n";
    exit(1);
}
if ($verbose) echo "Connected.\n";
$response = trim($response ?? '');
if (strpos($response, '220') !== 0) {
    echo "FAIL: 220 expected, got: " . addslashes($response) . "\n";
    fclose($fp);
    exit(1);
}
smtpLine($fp, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'), $verbose);
smtpReadToEnd($fp, $verbose);
smtpLine($fp, "STARTTLS", $verbose);
$err = null;
set_error_handler(function ($n, $msg) use (&$err) { $err = $msg; return true; });
$tlsOk = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
restore_error_handler();
if (!$tlsOk) {
    echo "FAIL: STARTTLS failed" . ($err ? " ($err)" : "") . "\n";
    fclose($fp);
    exit(1);
}
if ($verbose) echo "TLS OK.\n";
smtpLine($fp, "EHLO " . $smtpHost, $verbose);
smtpReadToEnd($fp, $verbose);
$auth = smtpLine($fp, "AUTH LOGIN", $verbose);
if ($auth === false || strpos($auth, '334') !== 0) {
    echo "FAIL: AUTH " . trim($auth ?: 'no response') . "\n";
    fclose($fp);
    exit(1);
}
smtpLine($fp, base64_encode($fromEmail), $verbose);
$pw = smtpLine($fp, base64_encode($password), $verbose);
if ($pw === false || strpos($pw, '235') !== 0) {
    echo "FAIL: Auth " . trim($pw ?: 'no response') . "\n";
    fclose($fp);
    exit(1);
}
if ($verbose) echo "Auth OK.\n";
$r1 = smtpLine($fp, "MAIL FROM:<$fromEmail>", $verbose);
$r2 = smtpLine($fp, "RCPT TO:<$toEmail>", $verbose);
$data = smtpLine($fp, "DATA", $verbose);
if ($data === false || strpos($data, '354') !== 0) {
    echo "FAIL: DATA " . trim($data ?: 'no response') . "\n";
    fclose($fp);
    exit(1);
}
fwrite($fp, $message . "\r\n.\r\n");
$last = '';
while (($line = fgets($fp, 512)) !== false) {
    $last = trim($line);
    if ($verbose) echo "  <- $last\n";
    if (preg_match('/^(250|550|553|554)\s/', $line)) {
        break;
    }
}
fwrite($fp, "QUIT\r\n");
fclose($fp);
if ($last && strpos($last, '250') === 0) {
    echo "OK: Email sent to $toEmail\n";
    exit(0);
}
echo "FAIL: " . ($last !== '' ? $last : 'no response after DATA') . "\n";
exit(1);
