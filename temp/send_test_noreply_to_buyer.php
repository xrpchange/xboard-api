<?php

/**
 * Send a test message to the buyer via the API test endpoint (same Mail path as the app).
 * Uses BUYER token (e.g. from Laravel logs when buyer starts deal or sends message).
 *
 * Usage (from project root):
 *   php temp/send_test_noreply_to_buyer.php <buyer_token> [message] [lot_id]
 *   php temp/send_test_noreply_to_buyer.php @path/to/token.txt [message] [lot_id]
 *
 * Requires in .env: APP_URL or TEST_API_URL. Token is NOT read from .env.
 * Endpoint: POST /api/v1/test/send-to-buyer (only when APP_ENV != production).
 */

$projectRoot = dirname(__DIR__);
$envFile = $projectRoot . '/.env';
$env = [];
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        $key = trim(substr($line, 0, strpos($line, '=')));
        $val = trim(substr($line, strpos($line, '=') + 1), " \t\"'");
        $env[$key] = $val;
    }
}

$getEnv = function (string $key, ?string $default = null) use ($env): ?string {
    $val = $env[$key] ?? getenv($key) ?: null;
    return $val !== '' ? $val : $default;
};

$apiBase = rtrim($getEnv('TEST_API_URL') ?? $getEnv('APP_URL') ?? '', '/');
if ($apiBase === '') {
    echo "ERROR: Set TEST_API_URL or APP_URL in .env (e.g. https://api.xdexboard.com)\n";
    exit(1);
}

$tokenArg = $argv[1] ?? null;
if ($tokenArg === null || $tokenArg === '') {
    echo "Usage: php temp/send_test_noreply_to_buyer.php <buyer_token> [message] [lot_id]\n";
    echo "       php temp/send_test_noreply_to_buyer.php @path/to/token.txt [message] [lot_id]\n";
    echo "Get buyer token from Laravel logs (e.g. when buyer starts deal: X-Escrow-Token / escrow_token_data).\n";
    exit(1);
}

if (strpos($tokenArg, '@') === 0) {
    $path = substr($tokenArg, 1);
    $path = is_file($path) ? $path : $projectRoot . '/' . $path;
    if (!is_file($path)) {
        echo "ERROR: Token file not found: " . $path . "\n";
        exit(1);
    }
    $token = trim(file_get_contents($path));
} else {
    $token = $tokenArg;
}

$message = $argv[2] ?? 'Test message from noreply — script via API (buyer token).';
$lotId = isset($argv[3]) ? (int) $argv[3] : 0;

$url = $apiBase . '/api/v1/test/send-to-buyer';
$body = http_build_query([
    'message' => $message,
    'lot_id' => $lotId,
]);

$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' =>
            "Content-Type: application/x-www-form-urlencoded\r\n" .
            "X-Escrow-Token: " . $token . "\r\n" .
            "Content-Length: " . strlen($body) . "\r\n",
        'content' => $body,
        'timeout' => 30,
    ],
]);

echo "POST " . $url . "\n";
echo "Message: " . substr($message, 0, 60) . (strlen($message) > 60 ? '...' : '') . "\n";

$response = @file_get_contents($url, false, $ctx);
$code = null;
if (isset($http_response_header[0])) {
    preg_match('/\d{3}/', $http_response_header[0], $m);
    $code = (int) ($m[0] ?? 0);
}

if ($response === false) {
    echo "ERROR: Request failed (no response)\n";
    exit(1);
}

$data = json_decode($response, true);
$ok = $code >= 200 && $code < 300;

if ($ok) {
    echo "OK: " . ($data['message'] ?? 'Message sent.') . (isset($data['to']) ? " To: " . $data['to'] : '') . "\n";
} else {
    echo "ERROR: HTTP " . $code . " — " . ($data['message'] ?? $response) . "\n";
    exit(1);
}
