<?php

/**
 * Send one email FROM the lot mailbox TO a given address (SMTP test).
 * Run on the server (where mail.xdexboard.com is reachable):
 *   php temp/send_from_lot_mailbox.php
 *
 * From: usdt_tron_tdjputgwmqx6mcfd5ax9yerzkyca2hpltm@xdexboard.com
 * To: tehnorgany@yandex.ru
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$fromEmail = 'usdt_tron_tdjputgwmqx6mcfd5ax9yerzkyca2hpltm@xdexboard.com';
$password = '45d1d9e7b9d889b20453b8989afb6f2843ddf301f755243afffc9d856fbf1f71';
$toEmail = 'tehnorgany@yandex.ru';
$smtpHost = config('services.lot_mailbox.smtp_host', config('services.lot_mailbox.imap_host', 'mail.xdexboard.com'));
$smtpPort = (int) config('services.lot_mailbox.smtp_port', 587);
$smtpEncryption = config('services.lot_mailbox.smtp_ssl', true) ? 'tls' : null; // 587 = tls, 465 = ssl

echo "=== Send from lot mailbox (SMTP test) ===\n";
echo "From: $fromEmail\n";
echo "To: $toEmail\n";
echo "SMTP: $smtpHost:$smtpPort\n\n";

config(['mail.mailers.lot_smtp' => [
    'transport' => 'smtp',
    'host' => $smtpHost,
    'port' => $smtpPort,
    'encryption' => $smtpEncryption,
    'username' => $fromEmail,
    'password' => $password,
    'timeout' => 15,
]]);

try {
    \Illuminate\Support\Facades\Mail::mailer('lot_smtp')
        ->raw(
            "This is a test email sent from the lot mailbox via SMTP (mail.xdexboard.com).\n\nIf you received this, SMTP for the lot chat mailbox is working.",
            function ($message) use ($fromEmail, $toEmail) {
                $message->to($toEmail)
                    ->from($fromEmail, 'Lot mailbox')
                    ->subject('SMTP test from lot mailbox');
            }
        );
    echo "OK: Email sent to $toEmail\n";
    exit(0);
} catch (\Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit(1);
}
