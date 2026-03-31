<?php

/**
 * Inspect recent IMAP messages in a lot mailbox and print raw headers/body preview.
 *
 * Usage:
 *   php temp/debug_lot_mailbox_recent_messages.php [subject_contains] [limit]
 *
 * Defaults:
 *   subject_contains = "Lot #8"
 *   limit = 10
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$email = 'usdt_tron_tdjputgwmqx6mcfd5ax9yerzkyca2hpltm@xdexboard.com';
$password = '45d1d9e7b9d889b20453b8989afb6f2843ddf301f755243afffc9d856fbf1f71';
$host = config('services.lot_mailbox.imap_host', 'mail.xdexboard.com');
$port = (int) config('services.lot_mailbox.imap_port', 993);
$encryption = config('services.lot_mailbox.imap_ssl', true) ? 'ssl' : false;

$subjectContains = trim((string) ($argv[1] ?? 'Lot #8'));
$limit = max(1, min(30, (int) ($argv[2] ?? 10)));

function extractDealTagFromRawHeader(string $rawHeader): ?string {
    if (preg_match('/^X-XBoard-Deal-Tag:\s*(.+)$/mi', $rawHeader, $matches) === 1) {
        return trim($matches[1]);
    }
    return null;
}

function oneLine(string $value, int $max = 200): string {
    $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
    if (strlen($value) > $max) {
        return substr($value, 0, $max) . '...';
    }
    return $value;
}

function stringifyValue(mixed $value): string {
    if ($value === null) {
        return '';
    }
    if (is_scalar($value)) {
        return (string) $value;
    }
    if ($value instanceof \DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }
    if (is_object($value) && method_exists($value, 'toDateTimeString')) {
        return (string) $value->toDateTimeString();
    }
    if (is_object($value) && method_exists($value, '__toString')) {
        return (string) $value;
    }
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: gettype($value);
}

echo "=== Debug recent lot mailbox messages ===\n";
echo "Host: {$host}:{$port}\n";
echo "User: {$email}\n";
echo "Subject filter: " . ($subjectContains !== '' ? $subjectContains : '(none)') . "\n";
echo "Limit: {$limit}\n\n";

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
    $folder = $client->getFolder('INBOX');
    $query = $folder->messages()->all();

    if ($subjectContains !== '') {
        $query->whereSubject($subjectContains);
    }

    $messages = $query->leaveUnread()->limit($limit)->get();

    if ($messages->count() === 0) {
        echo "No unread matches, retrying with all recent messages.\n\n";
        $query = $folder->messages()->all();
        if ($subjectContains !== '') {
            $query->whereSubject($subjectContains);
        }
        $messages = $query->limit($limit)->get();
    }

    echo "Matched messages: " . $messages->count() . "\n\n";

    foreach ($messages as $index => $message) {
        $header = $message->getHeader();
        $rawHeader = $header?->raw ?? '';
        $dealTag = extractDealTagFromRawHeader($rawHeader);
        $textBody = $message->getTextBody();
        $rawBody = $message->getRawBody();

        echo "----- Message #" . ($index + 1) . " -----\n";
        echo "UID: " . $message->getUid() . "\n";
        echo "Date: " . oneLine(stringifyValue($message->getDate())) . "\n";
        echo "Subject: " . oneLine($message->getSubject()) . "\n";
        echo "Message-ID: " . oneLine((string) $message->getMessageId()) . "\n";
        echo "DealTag header found: " . ($dealTag !== null ? 'YES' : 'NO') . "\n";
        if ($dealTag !== null) {
            echo "DealTag value: " . oneLine($dealTag, 500) . "\n";
        }
        echo "Raw headers preview:\n";
        echo oneLine($rawHeader, 1200) . "\n";
        echo "Text body preview:\n";
        echo oneLine($textBody, 500) . "\n";
        echo "Raw body preview:\n";
        echo oneLine($rawBody, 500) . "\n\n";
    }

    $client->disconnect();
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
