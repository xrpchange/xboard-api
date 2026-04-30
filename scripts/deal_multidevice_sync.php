<?php

declare(strict_types=1);

/**
 * Multi-device deal sync helper (manual UI + API verification).
 *
 * Usage examples:
 *   php scripts/deal_multidevice_sync.php lots:list --api=https://sandbox.api.xdexboard.com/api/v1 --limit=10
 *   php scripts/deal_multidevice_sync.php buyer:wait-active --api=... --buyer-token='{"token_hash":"..."}' --timeout=180
 *   php scripts/deal_multidevice_sync.php lot:watch --api=... --lot-id=123 --buyer-token='{"token_hash":"..."}' --timeout=900
 *   php scripts/deal_multidevice_sync.php lot:status --api=... --lot-id=123 --buyer-token='...'
 *   php scripts/deal_multidevice_sync.php lot:assert --api=... --lot-id=123 --buyer-token='...' --expect-tags=start_deal,payment_details
 *   php scripts/deal_multidevice_sync.php lot:assert-sequence --api=... --lot-id=123 --buyer-token='...' --expect-tags=start_deal,payment_details,buyer_crypto_tx
 *   php scripts/deal_multidevice_sync.php deal:e2e-trace --api=... --lot-id=123 --buyer-token='...' --allow-mock-receipt=true
 */

const DEFAULT_TIMEOUT_SECONDS = 600;
const DEFAULT_POLL_SECONDS = 3;

main($argv);

function main(array $argv): void
{
    [$command, $options] = parseArgs($argv);
    $apiBase = rtrim((string)($options['api'] ?? ''), '/');

    if ($apiBase === '') {
        fail("Missing required --api. Example: --api=https://sandbox.api.xdexboard.com/api/v1");
    }

    switch ($command) {
        case 'lots:list':
            cmdLotsList($apiBase, $options);
            return;
        case 'buyer:wait-active':
            cmdBuyerWaitActive($apiBase, $options);
            return;
        case 'lot:watch':
            cmdLotWatch($apiBase, $options);
            return;
        case 'lot:status':
            cmdLotStatus($apiBase, $options);
            return;
        case 'lot:assert':
            cmdLotAssert($apiBase, $options);
            return;
        case 'lot:assert-sequence':
            cmdLotAssertSequence($apiBase, $options);
            return;
        case 'deal:e2e-trace':
            cmdDealE2eTrace($apiBase, $options);
            return;
        default:
            fail("Unknown command '{$command}'. Supported: lots:list, buyer:wait-active, lot:watch, lot:status, lot:assert, lot:assert-sequence, deal:e2e-trace");
    }
}

function cmdLotsList(string $apiBase, array $options): void
{
    $limit = (int)($options['limit'] ?? 20);
    if ($limit <= 0) {
        $limit = 20;
    }

    $response = httpJson('GET', "{$apiBase}/exchange-lots?per_page={$limit}");
    $lots = (array)($response['data']['data'] ?? $response['data'] ?? []);

    if (count($lots) === 0) {
        out('No lots returned.');
        return;
    }

    out("Lots: " . count($lots));
    foreach ($lots as $lot) {
        $id = (int)($lot['id'] ?? 0);
        $from = (string)($lot['from_currency'] ?? '?');
        $to = (string)($lot['to_currency'] ?? '?');
        $active = (bool)($lot['is_active'] ?? false);
        $aml = (int)($lot['aml_max'] ?? 0);
        $buyerEmail = trim((string)($lot['buyer_lot_email'] ?? ''));
        $state = $buyerEmail !== '' ? 'IN_DEAL' : 'OPEN';
        out(sprintf(
            "- lot=%d pair=%s->%s active=%s aml_max=%d state=%s",
            $id,
            $from,
            $to,
            $active ? 'yes' : 'no',
            $aml,
            $state
        ));
    }
}

function cmdBuyerWaitActive(string $apiBase, array $options): void
{
    $buyerToken = trim((string)($options['buyer-token'] ?? ''));
    if ($buyerToken === '') {
        fail('Missing --buyer-token JSON string with escrow token.');
    }

    $timeout = max(10, (int)($options['timeout'] ?? DEFAULT_TIMEOUT_SECONDS));
    $poll = max(1, (int)($options['poll'] ?? DEFAULT_POLL_SECONDS));
    $startedAt = time();

    out('Waiting for buyer active deal...');
    while (true) {
        $resp = authGet($apiBase . '/exchange-lots/buyer-active-deals', $buyerToken);
        $items = (array)($resp['data']['items'] ?? []);

        if (count($items) > 0) {
            $first = (array)$items[0];
            $lot = (array)($first['lot'] ?? []);
            $lotId = (int)($lot['id'] ?? 0);
            out("Buyer active deal detected. lot_id={$lotId}");
            return;
        }

        if ((time() - $startedAt) >= $timeout) {
            fail("Timeout waiting for buyer active deal ({$timeout}s).");
        }

        sleep($poll);
    }
}

function cmdLotWatch(string $apiBase, array $options): void
{
    $lotId = (int)($options['lot-id'] ?? 0);
    if ($lotId <= 0) {
        fail('Missing --lot-id.');
    }

    $buyerToken = trim((string)($options['buyer-token'] ?? ''));
    $sellerToken = trim((string)($options['seller-token'] ?? ''));
    $authToken = $buyerToken !== '' ? $buyerToken : $sellerToken;
    if ($authToken === '') {
        fail('Provide --buyer-token or --seller-token.');
    }

    $timeout = max(30, (int)($options['timeout'] ?? DEFAULT_TIMEOUT_SECONDS));
    $poll = max(1, (int)($options['poll'] ?? DEFAULT_POLL_SECONDS));
    $startedAt = time();

    $lastMessageId = 0;
    $seenTags = [];
    out("Watching lot {$lotId}...");

    while (true) {
        $chat = authGet($apiBase . "/exchange-lots/{$lotId}/chat-messages", $authToken);
        $messages = (array)($chat['messages'] ?? []);

        foreach ($messages as $msg) {
            $id = (int)($msg['id'] ?? 0);
            if ($id <= $lastMessageId) {
                continue;
            }
            $lastMessageId = $id;
            $tag = normalizeTag($msg['deal_tag'] ?? null);
            if ($tag !== '') {
                $seenTags[] = $tag;
            }
            $body = trim((string)($msg['body'] ?? ''));
            $sender = (string)($msg['sender_side'] ?? 'unknown');
            out(sprintf(
                "[msg:%d] sender=%s tag=%s body=%s",
                $id,
                $sender,
                $tag !== '' ? $tag : '-',
                mb_strimwidth($body, 0, 120, '...')
            ));
        }

        $advice = nextActionAdvice($seenTags);
        out("next_action={$advice}");

        if (in_array('deal_completed_buyer', $seenTags, true) || in_array('deal_completed_seller', $seenTags, true)) {
            out('Deal reached completion tag. Stop watch.');
            return;
        }

        if ((time() - $startedAt) >= $timeout) {
            fail("Timeout in lot:watch ({$timeout}s).");
        }

        sleep($poll);
    }
}

function cmdLotStatus(string $apiBase, array $options): void
{
    [$lotId, $authToken] = resolveLotAuthOptions($options);
    $messages = fetchLotMessages($apiBase, $lotId, $authToken);
    $tags = extractTagsFromMessages($messages);
    $next = nextActionAdvice($tags);

    out("lot_id={$lotId}");
    out("messages_total=" . count($messages));
    out("tags=" . (count($tags) > 0 ? implode(',', $tags) : '(none)'));
    out("next_action={$next}");
}

function cmdLotAssert(string $apiBase, array $options): void
{
    [$lotId, $authToken] = resolveLotAuthOptions($options);
    $expectedRaw = trim((string)($options['expect-tags'] ?? ''));
    if ($expectedRaw === '') {
        fail('Missing --expect-tags, e.g. start_deal,payment_details');
    }

    $expected = array_values(array_filter(array_map('trim', explode(',', $expectedRaw))));
    $messages = fetchLotMessages($apiBase, $lotId, $authToken);
    $tags = extractTagsFromMessages($messages);

    $missing = [];
    foreach ($expected as $tag) {
        if (!in_array($tag, $tags, true)) {
            $missing[] = $tag;
        }
    }

    out("lot_id={$lotId}");
    out("actual_tags=" . (count($tags) > 0 ? implode(',', $tags) : '(none)'));
    out("expected_tags=" . implode(',', $expected));

    if (count($missing) > 0) {
        fail('Stage assert failed. Missing tags: ' . implode(',', $missing));
    }

    out('Stage assert OK.');
}

function cmdLotAssertSequence(string $apiBase, array $options): void
{
    [$lotId, $authToken] = resolveLotAuthOptions($options);
    $expectedRaw = trim((string)($options['expect-tags'] ?? ''));
    if ($expectedRaw === '') {
        fail('Missing --expect-tags, e.g. start_deal,payment_details,buyer_crypto_tx');
    }

    $allowMockReceipt = toBool($options['allow-mock-receipt'] ?? false);
    $expected = normalizeExpectedTags(
        array_values(array_filter(array_map('trim', explode(',', $expectedRaw)))),
        $allowMockReceipt
    );
    $messages = fetchLotMessages($apiBase, $lotId, $authToken);
    $actual = normalizeActualTags(extractOrderedTagsFromMessages($messages), $allowMockReceipt);

    out("lot_id={$lotId}");
    out("actual_sequence=" . (count($actual) > 0 ? implode(' -> ', $actual) : '(none)'));
    out("expected_sequence=" . implode(' -> ', $expected));
    out("allow_mock_receipt=" . ($allowMockReceipt ? 'true' : 'false'));

    $cursor = 0;
    foreach ($expected as $tag) {
        while ($cursor < count($actual) && $actual[$cursor] !== $tag) {
            $cursor++;
        }
        if ($cursor >= count($actual)) {
            fail("Sequence assert failed. Missing or out-of-order tag: {$tag}");
        }
        $cursor++;
    }

    out('Sequence assert OK.');
}

function cmdDealE2eTrace(string $apiBase, array $options): void
{
    [$lotId, $authToken] = resolveLotAuthOptions($options);
    $allowMockReceipt = toBool($options['allow-mock-receipt'] ?? false);
    $jsonOutput = strtolower(trim((string)($options['output'] ?? ''))) === 'json';
    $messages = fetchLotMessages($apiBase, $lotId, $authToken);

    $actualOrdered = extractOrderedTagsFromMessages($messages);
    $actualUnique = array_values(array_unique($actualOrdered));
    $actualNormalized = normalizeActualTags($actualOrdered, $allowMockReceipt);
    $nextAction = nextActionAdvice($actualUnique);

    $result = [
        'ok' => true,
        'lot_id' => $lotId,
        'messages_total' => count($messages),
        'allow_mock_receipt' => $allowMockReceipt,
        'tags_unique' => $actualUnique,
        'sequence_actual' => $actualOrdered,
        'next_action' => $nextAction,
        'checks' => [],
    ];

    $fail = static function (string $message) use (&$result, $jsonOutput): void {
        $result['ok'] = false;
        $result['error'] = $message;
        if ($jsonOutput) {
            fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            exit(1);
        }
        fail($message);
    };

    if (!$jsonOutput) {
        out("lot_id={$lotId}");
        out("messages_total=" . count($messages));
        out("allow_mock_receipt=" . ($allowMockReceipt ? 'true' : 'false'));
        out("tags_unique=" . (count($actualUnique) > 0 ? implode(',', $actualUnique) : '(none)'));
        out("sequence_actual=" . (count($actualOrdered) > 0 ? implode(' -> ', $actualOrdered) : '(none)'));
        out("next_action={$nextAction}");
    }

    // Presence checks: must-have milestones in any non-empty deal.
    $presenceExpect = ['start_deal', 'payment_details'];
    $presenceMissing = array_values(array_filter($presenceExpect, static fn (string $t): bool => !in_array($t, $actualUnique, true)));
    if (count($presenceMissing) > 0) {
        $result['checks']['presence'] = [
            'ok' => false,
            'expected' => $presenceExpect,
            'missing' => $presenceMissing,
        ];
        $fail('E2E trace failed (presence): missing ' . implode(',', $presenceMissing));
    }
    $result['checks']['presence'] = [
        'ok' => true,
        'expected' => $presenceExpect,
    ];
    if (!$jsonOutput) {
        out('presence_assert=OK(start_deal,payment_details)');
    }

    // Sequence checks: first leg -> seller confirmation -> destination details -> seller tx.
    $expectedSequence = normalizeExpectedTags(
        ['start_deal', 'payment_details', 'receipt_confirmation', 'seller_received_funds', 'buyer_destination_details', 'seller_transfer_tx'],
        $allowMockReceipt
    );
    $coreErr = getSequenceError($actualNormalized, $expectedSequence);
    if ($coreErr !== null) {
        $result['checks']['core_flow'] = [
            'ok' => false,
            'expected' => $expectedSequence,
            'error' => $coreErr,
        ];
        $fail("E2E trace failed (core_flow): {$coreErr}");
    }
    $result['checks']['core_flow'] = [
        'ok' => true,
        'expected' => $expectedSequence,
    ];
    if (!$jsonOutput) {
        out('sequence_assert_core_flow=OK');
    }

    // Optional completion check if requested.
    if (toBool($options['require-completed'] ?? false)) {
        $completionExpected = normalizeExpectedTags(
            ['start_deal', 'payment_details', 'receipt_confirmation', 'seller_received_funds', 'buyer_destination_details', 'seller_transfer_tx', 'deal_completed_buyer'],
            $allowMockReceipt
        );
        $completionErr = getSequenceError($actualNormalized, $completionExpected);
        if ($completionErr !== null) {
            $result['checks']['completion_flow'] = [
                'ok' => false,
                'expected' => $completionExpected,
                'error' => $completionErr,
            ];
            $fail("E2E trace failed (completion_flow): {$completionErr}");
        }
        $result['checks']['completion_flow'] = [
            'ok' => true,
            'expected' => $completionExpected,
        ];
        if (!$jsonOutput) {
            out('sequence_assert_completion_flow=OK');
        }
    }

    if ($jsonOutput) {
        fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
}

function nextActionAdvice(array $seenTags): string
{
    $tags = array_values(array_unique($seenTags));

    if (!in_array('start_deal', $tags, true)) {
        return 'buyer_should_start_deal';
    }
    if (!in_array('payment_details', $tags, true)) {
        return 'seller_should_send_payment_details';
    }

    $buyerPaid = in_array('receipt_confirmation', $tags, true) || in_array('buyer_crypto_tx', $tags, true);
    if (!$buyerPaid) {
        return 'buyer_should_confirm_first_leg_payment';
    }
    if (!in_array('seller_received_funds', $tags, true)) {
        return 'seller_should_confirm_received_funds';
    }
    if (!in_array('buyer_destination_details', $tags, true)) {
        return 'buyer_should_send_destination_details';
    }
    if (!in_array('seller_transfer_tx', $tags, true)) {
        return 'seller_should_send_second_leg_tx';
    }
    if (!in_array('deal_completed_buyer', $tags, true)) {
        return 'buyer_should_confirm_completion';
    }

    return 'completed';
}

function resolveLotAuthOptions(array $options): array
{
    $lotId = (int)($options['lot-id'] ?? 0);
    if ($lotId <= 0) {
        fail('Missing --lot-id.');
    }

    $buyerToken = trim((string)($options['buyer-token'] ?? ''));
    $sellerToken = trim((string)($options['seller-token'] ?? ''));
    $authToken = $buyerToken !== '' ? $buyerToken : $sellerToken;
    if ($authToken === '') {
        fail('Provide --buyer-token or --seller-token.');
    }

    return [$lotId, $authToken];
}

function fetchLotMessages(string $apiBase, int $lotId, string $authToken): array
{
    $chat = authGet($apiBase . "/exchange-lots/{$lotId}/chat-messages", $authToken);
    return (array)($chat['messages'] ?? []);
}

function extractTagsFromMessages(array $messages): array
{
    $tags = [];
    foreach ($messages as $msg) {
        $tag = normalizeTag($msg['deal_tag'] ?? null);
        if ($tag !== '') {
            $tags[] = $tag;
        }
    }

    return array_values(array_unique($tags));
}

function extractOrderedTagsFromMessages(array $messages): array
{
    $tags = [];
    foreach ($messages as $msg) {
        $tag = normalizeTag($msg['deal_tag'] ?? null);
        if ($tag !== '') {
            $tags[] = $tag;
        }
    }
    return $tags;
}

function normalizeExpectedTags(array $tags, bool $allowMockReceipt): array
{
    if (!$allowMockReceipt) {
        return $tags;
    }

    return array_map(static function (string $tag): string {
        if ($tag === 'receipt_confirmation') {
            return 'first_leg_confirmed';
        }
        if ($tag === 'buyer_crypto_tx') {
            return 'first_leg_confirmed';
        }
        return $tag;
    }, $tags);
}

function normalizeActualTags(array $tags, bool $allowMockReceipt): array
{
    if (!$allowMockReceipt) {
        return $tags;
    }

    return array_map(static function (string $tag): string {
        if ($tag === 'receipt_confirmation' || $tag === 'buyer_crypto_tx') {
            return 'first_leg_confirmed';
        }
        return $tag;
    }, $tags);
}

function toBool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    $v = strtolower(trim((string)$value));
    return in_array($v, ['1', 'true', 'yes', 'y', 'on'], true);
}

function assertSequenceOrFail(array $actualOrdered, array $expectedOrdered, string $label): void
{
    $err = getSequenceError($actualOrdered, $expectedOrdered);
    if ($err !== null) {
        fail("E2E trace failed ({$label}): {$err}");
    }
}

function getSequenceError(array $actualOrdered, array $expectedOrdered): ?string
{
    $cursor = 0;
    foreach ($expectedOrdered as $tag) {
        while ($cursor < count($actualOrdered) && $actualOrdered[$cursor] !== $tag) {
            $cursor++;
        }
        if ($cursor >= count($actualOrdered)) {
            return "missing or out-of-order tag {$tag}";
        }
        $cursor++;
    }

    return null;
}

function authGet(string $url, string $escrowTokenJson): array
{
    return httpJson('GET', $url, [
        'X-Escrow-Token' => $escrowTokenJson,
    ]);
}

function httpJson(string $method, string $url, array $headers = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        fail("Failed to init curl for {$url}");
    }

    $allHeaders = array_merge(['Accept' => 'application/json'], $headers);
    $headerLines = [];
    foreach ($allHeaders as $name => $value) {
        $headerLines[] = "{$name}: {$value}";
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_TIMEOUT => 30,
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        fail("HTTP error: {$error}");
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) {
        fail("Invalid JSON response ({$status}) from {$url}: {$body}");
    }

    if ($status >= 400) {
        $msg = (string)($decoded['message'] ?? 'HTTP error');
        $code = (string)($decoded['error_code'] ?? '-');
        fail("HTTP {$status} {$msg} (error_code={$code}) url={$url}");
    }

    return $decoded;
}

function normalizeTag(mixed $tagRaw): string
{
    if (is_array($tagRaw)) {
        $tag = (string)($tagRaw['message_type'] ?? '');
        return trim($tag);
    }
    if (!is_string($tagRaw) || trim($tagRaw) === '') {
        return '';
    }

    $decoded = json_decode($tagRaw, true);
    if (is_array($decoded)) {
        return trim((string)($decoded['message_type'] ?? ''));
    }

    return trim($tagRaw);
}

function parseArgs(array $argv): array
{
    if (count($argv) < 2) {
        fail('Command required. Example: lots:list');
    }

    $command = (string)$argv[1];
    $options = [];

    for ($i = 2; $i < count($argv); $i++) {
        $arg = (string)$argv[$i];
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $eq = strpos($arg, '=');
        if ($eq === false) {
            $options[substr($arg, 2)] = true;
            continue;
        }
        $key = substr($arg, 2, $eq - 2);
        $val = substr($arg, $eq + 1);
        $options[$key] = $val;
    }

    return [$command, $options];
}

function out(string $line): void
{
    fwrite(STDOUT, '[' . gmdate('Y-m-d\TH:i:s\Z') . "] {$line}\n");
}

function fail(string $message): void
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}
