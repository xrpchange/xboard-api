<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPMailer\DKIMValidator\Validator as DKIMValidator;
use PHPMailer\DKIMValidator\DKIMException;

/**
 * @OA\Tag(
 *     name="Payment Verification",
 *     description="Endpoints for verifying payment emails with DKIM"
 * )
 */
class PaymentVerificationController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/v1/payments/verify-email",
     *     summary="Verify payment email (multiple formats supported)",
     *     tags={"Payment Verification"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email_data", "format"},
     *             @OA\Property(property="email_data", type="string", description="Email content: base64 raw email, .eml file, or plain text copy-paste"),
     *             @OA\Property(property="format", type="string", enum={"raw", "eml", "plain"}, description="Format: raw (IMAP/POP3), eml (file), plain (copy-paste)"),
     *             @OA\Property(property="from_address", type="string", description="Required for plain format: sender email address"),
     *         )
     *     ),
     *     @OA\Response(response=200, description="Payment verified"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email_data' => 'required|string',
            'format' => 'required|in:raw,eml,plain',
            'from_address' => 'required_if:format,plain|string|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $format = $request->format;
            $emailData = $request->email_data;
            
            // Process based on format
            switch ($format) {
                case 'raw':
                    // IMAP/POP3 raw email (base64 encoded)
                    $rawEmail = base64_decode($emailData);
                    break;
                    
                case 'eml':
                    // .eml file (base64 encoded)
                    $rawEmail = base64_decode($emailData);
                    break;
                    
                case 'plain':
                    // Plain text copy-paste (no DKIM, but we check patterns)
                    $rawEmail = $this->constructEmailFromPlainText($emailData, $request->from_address);
                    break;
                    
                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid format'
                    ], 422);
            }
            
            if (!$rawEmail) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid email format'
                ], 422);
            }

            // Verify sender (bank domain whitelist from banks.allowed_domain)
            $senderCheck = $this->verifySender($rawEmail);
            if (!$senderCheck['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email is not from an allowed bank',
                    'error' => $senderCheck['error'],
                    'sender_domain' => $senderCheck['domain'] ?? null,
                    'sender_known' => false,
                ], 422);
            }

            // Verify DKIM signature (skip for plain format). Prefer manual verification (RFC 6376), then PHPMailer.
            $dkimResult = ['valid' => false, 'signature' => null, 'domain' => null, 'error' => null];
            if ($format !== 'plain') {
                $dkimResult = $this->verifyDKIMManual($rawEmail);
                if (!$dkimResult['valid']) {
                    $dkimResult = $this->verifyDKIM($rawEmail);
                }
                if (!$dkimResult['valid']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'DKIM verification failed',
                        'error' => $dkimResult['error'],
                        'hint' => 'Try forwarding email as attachment or use IMAP/POP3 method'
                    ], 422);
                }
            } else {
                // For plain format, we rely on pattern matching only
                Log::info("Email verification without DKIM (plain format)", [
                    'from' => $request->from_address,
                ]);
            }

            // Check payment pattern
            $paymentData = $this->extractPaymentData($rawEmail, $senderCheck['domain']);

            Log::info("Email verification test", [
                'bank' => $senderCheck['domain'],
                'dkim_domain' => $dkimResult['domain'],
                'dkim_valid' => $dkimResult['valid'],
            ]);

            return response()->json([
                'success' => true,
                'message' => $dkimResult['valid'] ? 'DKIM verification successful' : 'Email verified (no DKIM)',
                'data' => [
                    'bank' => $senderCheck['domain'],
                    'sender_bank_name' => $senderCheck['bank_name'] ?? null,
                    'sender_known' => true,
                    'amount' => $paymentData['amount'] ?? null,
                    'verified_at' => now()->toISOString(),
                    'dkim_verified' => $dkimResult['valid'],
                    'dkim_domain' => $dkimResult['domain'],
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Email verification error: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error verifying email: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Extract sender information from email (without validation)
     */
    private function extractSenderInfo(string $rawEmail): array
    {
        $result = [
            'email' => null,
            'domain' => null,
            'from' => null,
        ];

        // Normalize line endings (email uses \r\n, but may come as \n)
        $rawEmail = str_replace("\r\n", "\n", $rawEmail);
        $rawEmail = str_replace("\r", "\n", $rawEmail);
        
        // Extract From header (may be multiline)
        $lines = explode("\n", $rawEmail);
        $inFromHeader = false;
        $fromLines = [];
        
        foreach ($lines as $line) {
            // Check if this is the start of From header
            if (preg_match('/^From:\s*(.+)$/i', $line, $matches)) {
                $inFromHeader = true;
                $fromLines[] = trim($matches[1]);
            } elseif ($inFromHeader) {
                // Check if this is a continuation line (starts with space/tab)
                if (preg_match('/^\s+(.+)$/', $line, $matches)) {
                    $fromLines[] = trim($matches[1]);
                } else {
                    // End of From header (empty line or next header)
                    if (trim($line) === '' || preg_match('/^[A-Za-z-]+:/', $line)) {
                        break;
                    }
                }
            }
        }
        
        if (!empty($fromLines)) {
            $from = implode(' ', $fromLines);
            $result['from'] = $from;
            
            // Extract email address from From header
            if (preg_match('/<([^>]+)>/', $from, $emailMatch)) {
                $result['email'] = trim($emailMatch[1]);
            } elseif (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $from, $emailMatch)) {
                $result['email'] = trim($emailMatch[1]);
            }
            
            // Extract domain
            if ($result['email']) {
                $result['domain'] = strtolower(substr(strrchr($result['email'], "@"), 1));
            } elseif (preg_match('/@([^\s>;]+)/', $from, $domainMatch)) {
                $result['domain'] = strtolower(trim($domainMatch[1]));
            }
        }

        return $result;
    }

    /**
     * Verify email sender against banks.allowed_domain whitelist.
     * Returns valid, domain, bank_name (for display "Letter from bank X"), and error when not in whitelist.
     */
    private function verifySender(string $rawEmail, ?int $expectedBankId = null): array
    {
        $result = [
            'valid' => false,
            'from' => null,
            'domain' => null,
            'bank_name' => null,
            'error' => null,
        ];

        $senderInfo = $this->extractSenderInfo($rawEmail);

        if (!$senderInfo['domain']) {
            $result['error'] = 'Could not extract sender domain from email';
            return $result;
        }

        $result['domain'] = $senderInfo['domain'];
        $result['from'] = $senderInfo['from'];

        if ($expectedBankId !== null) {
            $bank = Bank::withAllowedDomain()->where('id', $expectedBankId)->first();
            if (!$bank) {
                $result['error'] = "Bank id '{$expectedBankId}' is not in DKIM whitelist";
                return $result;
            }

            $allowedDomain = strtolower(trim($bank->allowed_domain ?? ''));
            if ($senderInfo['domain'] === $allowedDomain
                || substr($senderInfo['domain'], -strlen($allowedDomain) - 1) === '.' . $allowedDomain) {
                $result['valid'] = true;
                $result['bank_name'] = $bank->name;
                return $result;
            }

            $result['error'] = "Sender domain '{$senderInfo['domain']}' does not match expected bank '{$bank->name}'";
            return $result;
        } else {
            $banks = Bank::withAllowedDomain()->get();
            foreach ($banks as $bank) {
                $allowedDomain = strtolower(trim($bank->allowed_domain ?? ''));
                if ($allowedDomain === '') {
                    continue;
                }
                // Exact match or subdomain (e.g. no-reply@sberbank.ru or mail.sberbank.ru)
                if ($senderInfo['domain'] === $allowedDomain
                    || substr($senderInfo['domain'], -strlen($allowedDomain) - 1) === '.' . $allowedDomain) {
                    $result['valid'] = true;
                    $result['bank_name'] = $bank->name;
                    return $result;
                }
            }
        }

        $result['error'] = "Domain '{$senderInfo['domain']}' not in allowed banks (unknown sender)";
        return $result;
    }

    /**
     * Verify DKIM signature using PHPMailer DKIMValidator library
     * Performs full cryptographic verification: DNS lookup, canonicalization, hashing, and RSA signature verification
     */
    private function verifyDKIM(string $rawEmail): array
    {
        $result = [
            'valid' => false,
            'signature' => null,
            'domain' => null,
            'error' => null,
        ];

        try {
            // Use PHPMailer DKIMValidator for full cryptographic verification
            $dkimValidator = new DKIMValidator($rawEmail);
            
            // Perform full validation (DNS lookup, canonicalization, hashing, RSA verification)
            // validate() returns array of results for each DKIM signature found
            $validationResults = $dkimValidator->validate();
            
            // Check if at least one signature is valid
            // validate() returns array of arrays, each containing status and reason
            $hasValidSignature = false;
            $validDomain = null;
            $errorMessages = [];
            
            // Process all validation results
            foreach ($validationResults as $signatureResults) {
                // Each signature can have multiple results (different public keys tried)
                foreach ($signatureResults as $sigResult) {
                    if (isset($sigResult['status']) && $sigResult['status'] === 'SUCCESS') {
                        $hasValidSignature = true;
                        // Extract domain from the first valid signature
                        if (!$validDomain && preg_match('/DKIM-Signature:.*?d=([^;\s]+)/is', $rawEmail, $domainMatch)) {
                            $validDomain = trim($domainMatch[1]);
                        }
                        break 2; // Found valid signature, no need to check others
                    } elseif (isset($sigResult['reason'])) {
                        $errorMessages[] = $sigResult['reason'];
                    }
                }
            }
            
            if ($hasValidSignature) {
                $result['valid'] = true;
                $result['domain'] = $validDomain;
                
                Log::info("DKIM signature verified successfully via PHPMailer DKIMValidator", [
                    'domain' => $result['domain'],
                    'total_signatures' => count($validationResults),
                    'validation_results' => $validationResults
                ]);
            } else {
                $result['valid'] = false;
                
                // Extract domain from email even if validation failed
                if (preg_match('/DKIM-Signature:.*?d=([^;\s]+)/is', $rawEmail, $domainMatch)) {
                    $result['domain'] = trim($domainMatch[1]);
                }
                
                // Combine error messages
                $result['error'] = !empty($errorMessages) 
                    ? implode('; ', array_unique($errorMessages))
                    : 'DKIM signature verification failed';
                
                Log::warning("DKIM signature verification failed", [
                    'domain' => $result['domain'] ?? null,
                    'error' => $result['error'],
                    'total_signatures' => count($validationResults),
                    'validation_results' => $validationResults
                ]);
            }
            
        } catch (DKIMException $e) {
            // DKIMException is thrown when signature is not found or other parsing errors
            $result['valid'] = false;
            $result['error'] = $e->getMessage();
            
            // Try to extract domain from email even if exception occurred
            if (preg_match('/DKIM-Signature:.*?d=([^;\s]+)/is', $rawEmail, $domainMatch)) {
                $result['domain'] = trim($domainMatch[1]);
            }
            
            Log::warning("DKIM validation error", [
                'error' => $e->getMessage(),
                'domain' => $result['domain'] ?? null,
                'has_dkim_in_email' => strpos($rawEmail, 'DKIM-Signature') !== false
            ]);
        } catch (\Exception $e) {
            // Catch any other exceptions
            $result['valid'] = false;
            $result['error'] = 'DKIM verification error: ' . $e->getMessage();
            
            // Try to extract domain from email even if exception occurred
            if (preg_match('/DKIM-Signature:.*?d=([^;\s]+)/is', $rawEmail, $domainMatch)) {
                $result['domain'] = trim($domainMatch[1]);
            }
            
            Log::error("DKIM verification exception", [
                'error' => $e->getMessage(),
                'domain' => $result['domain'] ?? null,
                'trace' => $e->getTraceAsString()
            ]);
        }

        return $result;
    }

    /**
     * RFC 6376 body canonicalization for DKIM body hash (bh=).
     * l= if present: take first L octets of raw body BEFORE canonicalization.
     */
    private function canonicalizeBody(string $body, string $style, ?int $l = null): string
    {
        if ($l !== null) {
            $body = substr($body, 0, $l);
        }
        if ($body === '') {
            return "\r\n";
        }
        $b = str_replace("\r\n", "\n", $body);
        if ($style === 'relaxed') {
            $b = preg_replace('/[ \t]+$/m', '', $b);
            $b = preg_replace('/[ \t]+/m', ' ', $b);
        }
        $b = preg_replace('/\n+$/', '', $b);
        $b = str_replace("\n", "\r\n", $b);
        if (!str_ends_with($b, "\r\n")) {
            $b .= "\r\n";
        }
        return $b;
    }

    /**
     * Log DKIM body hash diagnostic: parse DKIM-Signature, extract body, canonicalize, recompute bh, compare.
     * Run before verifyDKIM so we can see computed_bh vs signature bh and hex of canon body (first/last 100).
     */
    private function logDkimBodyHashDiagnostic(string $rawEmail): void
    {
        $pos = strpos($rawEmail, "\r\n\r\n");
        if ($pos === false) {
            $pos = strpos($rawEmail, "\n\n");
            $rawBody = $pos !== false ? substr($rawEmail, $pos + 2) : '';
        } else {
            $rawBody = substr($rawEmail, $pos + 4);
        }

        if (!preg_match_all('/^DKIM-Signature:\s*(.+)$/mi', $rawEmail, $matches)) {
            Log::info('DKIM body hash diagnostic: no DKIM-Signature header found');
            return;
        }

        foreach ($matches[1] as $i => $sigLine) {
            $sig = preg_replace('/\r?\n\s+/', ' ', $sigLine);
            $tags = [];
            foreach (explode(';', $sig) as $pair) {
                $eq = strpos($pair, '=');
                if ($eq !== false) {
                    $tags[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
                }
            }
            $c = $tags['c'] ?? 'simple/simple';
            $parts = explode('/', $c);
            $bodyC = $parts[1] ?? 'simple';
            $bh = $tags['bh'] ?? '';
            $a = $tags['a'] ?? 'rsa-sha256';
            $algo = str_replace('rsa-', '', $a);
            if ($algo !== 'sha256' && $algo !== 'sha1') {
                $algo = 'sha256';
            }
            $l = isset($tags['l']) ? (int) $tags['l'] : null;

            Log::info('DKIM tags', [
                'sig_index' => $i,
                'c' => $c,
                'bh' => $bh,
                'algo' => $algo,
                'l' => $l,
                'd' => $tags['d'] ?? null,
            ]);

            $canonBody = $this->canonicalizeBody($rawBody, $bodyC, $l);
            $computedBh = base64_encode(hash($algo, $canonBody, true));
            $bhMatch = hash_equals($bh, $computedBh);

            Log::info('Body hash debug', [
                'sig_index' => $i,
                'raw_body_len' => strlen($rawBody),
                'canon_body_len' => strlen($canonBody),
                'computed_bh' => $computedBh,
                'signature_bh' => $bh,
                'match' => $bhMatch,
                'canon_first_100_hex' => bin2hex(substr($canonBody, 0, 100)),
                'canon_last_100_hex' => bin2hex(substr($canonBody, -100)),
            ]);
        }
    }

    /**
     * Parse raw email into headers list (name => value, last occurrence wins for duplicates) and raw body.
     * Headers are unfolded (continuation lines merged).
     */
    private function parseEmailHeadersAndBody(string $rawEmail): array
    {
        $sep = strpos($rawEmail, "\r\n\r\n");
        if ($sep === false) {
            $sep = strpos($rawEmail, "\n\n");
            $headerBlock = $sep !== false ? substr($rawEmail, 0, $sep) : $rawEmail;
            $rawBody = $sep !== false ? substr($rawEmail, $sep + 2) : '';
        } else {
            $headerBlock = substr($rawEmail, 0, $sep);
            $rawBody = substr($rawEmail, $sep + 4);
        }
        $lines = preg_split('/\r\n|\n/', $headerBlock);
        $headers = [];
        $currentName = null;
        $currentValue = null;
        foreach ($lines as $line) {
            if (preg_match('/^([\x21-\x39\x3B-\x7E]+):\s*(.*)$/', $line, $m)) {
                if ($currentName !== null) {
                    $headers[] = ['name' => $currentName, 'value' => trim($currentValue ?? '')];
                }
                $currentName = $m[1];
                $currentValue = $m[2];
            } elseif ($currentName !== null && preg_match('/^\s+(.*)$/', $line, $m)) {
                $currentValue = ($currentValue ?? '') . ' ' . trim($m[1]);
            }
        }
        if ($currentName !== null) {
            $headers[] = ['name' => $currentName, 'value' => trim($currentValue ?? '')];
        }
        return ['headers' => $headers, 'body' => $rawBody];
    }

    /**
     * RFC 6376 relaxed header canonicalization: lowercase name, collapse WSP to single space, strip trailing WSP.
     */
    private function canonicalizeHeaderRelaxed(string $name, string $value): string
    {
        $name = strtolower($name);
        $value = preg_replace('/[ \t]+/', ' ', $value);
        $value = trim($value);
        return $name . ':' . $value;
    }

    /**
     * RFC 6376 simple header canonicalization: no change except CRLF.
     */
    private function canonicalizeHeaderSimple(string $name, string $value): string
    {
        return $name . ':' . $value;
    }

    /**
     * Build the DKIM signing string (headers in h= order, canonicalized; DKIM-Signature with b= and bh= empty).
     */
    private function buildDkimSigningString(array $headers, string $headerCanon, array $hOrder, string $dkimSignatureValueForSigning): string
    {
        $lastValueByName = [];
        foreach ($headers as $h) {
            $lastValueByName[strtolower($h['name'])] = ['name' => $h['name'], 'value' => $h['value']];
        }
        $canon = $headerCanon === 'relaxed' ? 'relaxed' : 'simple';
        $parts = [];
        foreach ($hOrder as $nameLower) {
            $nameLower = strtolower(trim($nameLower));
            if ($nameLower === '') {
                continue;
            }
            if (!isset($lastValueByName[$nameLower])) {
                continue;
            }
            $entry = $lastValueByName[$nameLower];
            if ($nameLower === 'dkim-signature') {
                $value = $dkimSignatureValueForSigning;
            } else {
                $value = $entry['value'];
            }
            $parts[] = $canon === 'relaxed'
                ? $this->canonicalizeHeaderRelaxed($entry['name'], $value)
                : $this->canonicalizeHeaderSimple($entry['name'], $value);
        }
        return implode("\r\n", $parts) . "\r\n";
    }

    /**
     * Get DKIM public key from DNS (selector._domainkey.domain TXT).
     */
    private function getDkimPublicKey(string $selector, string $domain): ?string
    {
        $record = $selector . '._domainkey.' . $domain;
        $txt = @dns_get_record($record, DNS_TXT);
        if ($txt === false || empty($txt)) {
            return null;
        }
        $combined = '';
        foreach ($txt as $r) {
            if (isset($r['txt'])) {
                $combined .= is_array($r['txt']) ? implode('', $r['txt']) : $r['txt'];
            }
        }
        if ($combined === '') {
            return null;
        }
        $tags = [];
        foreach (explode(';', $combined) as $pair) {
            $eq = strpos($pair, '=');
            if ($eq !== false) {
                $tags[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
            }
        }
        return $tags['p'] ?? null;
    }

    /**
     * Manual DKIM verification (RFC 6376): body hash + header signature. Used when PHPMailer validator fails.
     */
    private function verifyDKIMManual(string $rawEmail): array
    {
        $result = [
            'valid' => false,
            'signature' => null,
            'domain' => null,
            'error' => null,
        ];

        $parsed = $this->parseEmailHeadersAndBody($rawEmail);
        $headers = $parsed['headers'];
        $rawBody = $parsed['body'];

        $dkimSignatureHeaders = [];
        foreach ($headers as $h) {
            if (strtolower($h['name']) === 'dkim-signature') {
                $dkimSignatureHeaders[] = $h['value'];
            }
        }
        if (empty($dkimSignatureHeaders)) {
            $result['error'] = 'No DKIM-Signature header';
            return $result;
        }

        foreach ($dkimSignatureHeaders as $sigIdx => $sigValue) {
            $tags = [];
            foreach (explode(';', $sigValue) as $pair) {
                $eq = strpos($pair, '=');
                if ($eq !== false) {
                    $tags[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
                }
            }
            $d = $tags['d'] ?? null;
            $s = $tags['s'] ?? null;
            $h = $tags['h'] ?? null;
            $b = $tags['b'] ?? null;
            $bh = $tags['bh'] ?? null;
            $c = $tags['c'] ?? 'simple/simple';
            $a = $tags['a'] ?? 'rsa-sha256';
            $algo = str_replace('rsa-', '', $a);
            if ($algo !== 'sha256' && $algo !== 'sha1') {
                $algo = 'sha256';
            }
            $l = isset($tags['l']) ? (int) $tags['l'] : null;
            $parts = explode('/', $c);
            $headerCanon = $parts[0] ?? 'simple';
            $bodyCanon = $parts[1] ?? 'simple';
            $hOrder = $h ? explode(':', $h) : [];

            if (!$d || !$s || !$b || !$bh || empty($hOrder)) {
                continue;
            }

            $canonBody = $this->canonicalizeBody($rawBody, $bodyCanon, $l);
            $computedBh = base64_encode(hash($algo, $canonBody, true));
            if (!hash_equals($bh, $computedBh)) {
                Log::info('DKIM manual: body hash mismatch', ['sig_index' => $sigIdx, 'd' => $d]);
                continue;
            }

            $dkimValueForSigning = preg_replace('/\bb=([^;\s]+)/', 'b=', $sigValue);
            $dkimValueForSigning = preg_replace('/\bbh=([^;\s]+)/', 'bh=', $dkimValueForSigning);
            $signingString = $this->buildDkimSigningString($headers, $headerCanon, $hOrder, $dkimValueForSigning);

            $hash = hash($algo, $signingString, true);
            $signatureBinary = base64_decode(str_replace([' ', "\t", "\r", "\n"], '', $b), true);
            if ($signatureBinary === false) {
                continue;
            }

            $pem = $this->getDkimPublicKey($s, $d);
            if ($pem === null || $pem === '') {
                Log::info('DKIM manual: no public key in DNS', ['selector' => $s, 'domain' => $d]);
                continue;
            }
            $pubKey = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($pem, 64, "\n") . "-----END PUBLIC KEY-----";
            $key = openssl_pkey_get_public($pubKey);
            if ($key === false) {
                Log::info('DKIM manual: invalid public key', ['d' => $d]);
                continue;
            }
            $opensslAlgo = $algo === 'sha256' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
            $ok = openssl_verify($signingString, $signatureBinary, $key, $opensslAlgo);
            openssl_pkey_free($key);
            if ($ok === 1) {
                $result['valid'] = true;
                $result['domain'] = $d;
                $result['signature'] = $b;
                Log::info('DKIM manual verification passed', ['domain' => $d, 'sig_index' => $sigIdx]);
                return $result;
            }
        }

        $result['error'] = 'DKIM manual verification failed (body hash or signature)';
        return $result;
    }

    /**
     * Extract payment data from email
     */
    private function extractPaymentData(string $rawEmail, string $bankDomain): array
    {
        $data = [
            'bank' => $bankDomain,
            'amount' => null,
            'date' => null,
            'reference' => null,
        ];

        // Extract body
        $bodyStart = strpos($rawEmail, "\r\n\r\n");
        $body = $bodyStart !== false ? substr($rawEmail, $bodyStart + 4, 5000) : '';

        // Extract amount (example patterns)
        if (preg_match('/(?:сумма|amount|переведено|списано).*?(\d+[.,]\d+)/i', $body, $matches)) {
            $data['amount'] = (float) str_replace(',', '.', $matches[1]);
        }

        // Extract date
        if (preg_match('/(\d{1,2}[.\/]\d{1,2}[.\/]\d{2,4})/', $body, $matches)) {
            $data['date'] = $matches[1];
        }

        // Extract reference number
        if (preg_match('/(?:номер|reference|ref).*?(\d{6,})/i', $body, $matches)) {
            $data['reference'] = $matches[1];
        }

        return $data;
    }

    /**
     * Store email proof (encrypted)
     */
    private function storeEmailProof(Transaction $transaction, string $rawEmail, array $dkimResult, array $paymentData): void
    {
        // Encrypt email before storage
        $encrypted = encrypt($rawEmail);
        
        // Store in database or file system
        // TODO: Create email_verifications table
        // For now, log for debugging
        Log::info("Email proof stored", [
            'transaction_id' => $transaction->id,
            'email_hash' => hash('sha256', $rawEmail),
            'dkim_domain' => $dkimResult['domain'],
        ]);
    }

    /**
     * Construct email from plain text copy-paste
     * Used when user manually copies email content
     */
    private function constructEmailFromPlainText(string $plainText, string $fromAddress): string
    {
        // Extract domain from from_address
        $domain = substr(strrchr($fromAddress, "@"), 1);
        
        // Construct minimal email headers
        $headers = [
            "From: {$fromAddress}",
            "Date: " . date('r'),
            "Subject: Payment Notification",
        ];
        
        // Combine headers and body
        $email = implode("\r\n", $headers) . "\r\n\r\n" . $plainText;
        
        return $email;
    }

    /**
     * @OA\Post(
     *     path="/api/v1/payments/verify-email-content",
     *     summary="Verify email content (DKIM and sender domain) without forwarding",
     *     tags={"Payment Verification"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email_raw"},
     *             @OA\Property(property="email_raw", type="string", description="Base64 encoded raw email (from IMAP/POP3 or .eml)"),
     *             @OA\Property(property="bank_id", type="integer", nullable=true, description="Optional expected bank ID from X-XBoard-Deal-Tag. If provided, sender must match this bank's allowed_domain."),
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Email verification result",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="dkim_valid", type="boolean", description="Whether DKIM signature is valid"),
     *                 @OA\Property(property="dkim_domain", type="string", nullable=true, description="Domain from DKIM signature"),
     *                 @OA\Property(property="sender_domain", type="string", nullable=true, description="Domain from From header"),
     *                 @OA\Property(property="sender_email", type="string", nullable=true, description="Email address from From header"),
     *                 @OA\Property(property="dkim_error", type="string", nullable=true, description="DKIM verification error if any"),
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function verifyEmailContent(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email_raw' => 'required|string',
            'bank_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Decode base64 (strict mode) — client sends base64(gzip(raw EML))
            $gzipped = base64_decode($request->email_raw, true);
            if ($gzipped === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid base64 in email_raw'
                ], 422);
            }

            $rawEmail = gzdecode($gzipped);
            if ($rawEmail === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to decompress gzip payload (email_raw must be base64-encoded gzipped raw EML)'
                ], 422);
            }

            Log::debug("verifyEmailContent: raw email after gzdecode", [
                'length' => strlen($rawEmail),
                'md5' => md5($rawEmail),
                'first_300' => substr($rawEmail, 0, 300),
                'last_300' => substr($rawEmail, -300),
            ]);
            Log::info("verifyEmailContent: received decoded email", [
                'decoded_length' => strlen($rawEmail),
                'has_from' => strpos($rawEmail, 'From:') !== false,
                'has_dkim' => strpos($rawEmail, 'DKIM-Signature') !== false,
            ]);

            // DKIM body hash diagnostic (RFC 6376): extract signature tags and manually recompute bh (INFO so it's visible)
            $this->logDkimBodyHashDiagnostic($rawEmail);

            // Extract sender domain and email
            $senderInfo = $this->extractSenderInfo($rawEmail);

            $expectedBankId = $request->filled('bank_id') ? (int) $request->input('bank_id') : null;
            Log::info('verifyEmailContent: request context', [
                'expected_bank_id' => $expectedBankId,
                'sender_domain' => $senderInfo['domain'],
                'sender_email' => $senderInfo['email'],
            ]);

            // Check sender against whitelist (or against exact bank when bank_id is provided)
            $senderCheck = $this->verifySender($rawEmail, $expectedBankId);
            Log::info('verifyEmailContent: sender check result', [
                'expected_bank_id' => $expectedBankId,
                'sender_known' => $senderCheck['valid'],
                'sender_bank_name' => $senderCheck['bank_name'] ?? null,
                'sender_check_error' => $senderCheck['error'] ?? null,
                'matched_domain' => $senderCheck['domain'] ?? null,
            ]);
            if (!$senderCheck['valid']) {
                Log::warning('verifyEmailContent: sender whitelist check failed', [
                    'expected_bank_id' => $expectedBankId,
                    'sender_domain' => $senderInfo['domain'],
                    'sender_email' => $senderInfo['email'],
                    'sender_check_error' => $senderCheck['error'] ?? null,
                ]);
            }

            // Prefer manual DKIM verification (correct body canonicalization and l= handling); fallback to PHPMailer
            $dkimResult = $this->verifyDKIMManual($rawEmail);
            if (!$dkimResult['valid']) {
                $dkimResult = $this->verifyDKIM($rawEmail);
            }
            if (!$dkimResult['valid']) {
                Log::warning('verifyEmailContent: dkim check failed', [
                    'expected_bank_id' => $expectedBankId,
                    'sender_domain' => $senderInfo['domain'],
                    'dkim_domain' => $dkimResult['domain'] ?? null,
                    'dkim_error' => $dkimResult['error'] ?? null,
                ]);
            }

            Log::info("Email content verification", [
                'raw_email_length' => strlen($rawEmail),
                'raw_email_starts_with' => substr($rawEmail, 0, 50),
                'has_from_in_email' => strpos($rawEmail, 'From:') !== false,
                'has_dkim_in_email' => strpos($rawEmail, 'DKIM-Signature') !== false,
                'sender_domain' => $senderInfo['domain'],
                'sender_email' => $senderInfo['email'],
                'sender_from' => $senderInfo['from'],
                'dkim_valid' => $dkimResult['valid'],
                'dkim_domain' => $dkimResult['domain'],
                'dkim_error' => $dkimResult['error'],
            ]);

            // Extract and save PDF attachments only after DKIM and bank whitelist checks pass
            $pdfAttachments = [];
            Log::info('verifyEmailContent: PDF extraction check', [
                'dkim_valid' => $dkimResult['valid'],
                'sender_known' => $senderCheck['valid'],
                'will_extract_pdf' => $dkimResult['valid'] && $senderCheck['valid'],
            ]);
            if ($dkimResult['valid'] && $senderCheck['valid']) {
                $pdfAttachments = $this->extractAndSavePdfAttachments($rawEmail);
                Log::info('verifyEmailContent: PDF extraction done', ['count' => count($pdfAttachments)]);
            } else {
                Log::info('verifyEmailContent: skipping PDF extraction (DKIM or sender whitelist check failed)', [
                    'expected_bank_id' => $expectedBankId,
                    'dkim_valid' => $dkimResult['valid'],
                    'sender_known' => $senderCheck['valid'],
                    'dkim_error' => $dkimResult['error'] ?? null,
                    'sender_check_error' => $senderCheck['error'] ?? null,
                ]);
            }

            if ($dkimResult['valid'] && $senderCheck['valid'] && count($pdfAttachments) === 0) {
                Log::warning('verifyEmailContent: invalid receipt, no PDF attachments', [
                    'bank_id' => $expectedBankId,
                    'sender_email' => $senderInfo['email'],
                    'sender_domain' => $senderInfo['domain'],
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Email is invalid: no PDF attachments found',
                    'data' => [
                        'dkim_valid' => true,
                        'dkim_domain' => $dkimResult['domain'] ? $this->cleanUtf8String($dkimResult['domain']) : null,
                        'sender_domain' => $senderInfo['domain'] ? $this->cleanUtf8String($senderInfo['domain']) : null,
                        'sender_email' => $senderInfo['email'] ? $this->cleanUtf8String($senderInfo['email']) : null,
                        'sender_bank_name' => $senderCheck['bank_name'] ? $this->cleanUtf8String($senderCheck['bank_name']) : null,
                        'sender_known' => $senderCheck['valid'],
                        'dkim_error' => null,
                        'pdf_attachments' => [],
                    ],
                ]);
            }

            // Ensure all strings are valid UTF-8 before JSON encoding
            $responseData = [
                'success' => true,
                'message' => $dkimResult['valid']
                    ? 'Email verified: DKIM signature is valid'
                    : 'Email verified: DKIM signature not found or invalid',
                'data' => [
                    'dkim_valid' => $dkimResult['valid'],
                    'dkim_domain' => $dkimResult['domain'] ? $this->cleanUtf8String($dkimResult['domain']) : null,
                    'sender_domain' => $senderInfo['domain'] ? $this->cleanUtf8String($senderInfo['domain']) : null,
                    'sender_email' => $senderInfo['email'] ? $this->cleanUtf8String($senderInfo['email']) : null,
                    'sender_bank_name' => $senderCheck['bank_name'] ? $this->cleanUtf8String($senderCheck['bank_name']) : null,
                    'sender_known' => $senderCheck['valid'],
                    'dkim_error' => $dkimResult['error'] ? $this->cleanUtf8String($dkimResult['error']) : null,
                    'pdf_attachments' => $pdfAttachments,
                ]
            ];

            Log::info('verifyEmailContent: response payload summary', [
                'expected_bank_id' => $expectedBankId,
                'sender_known' => $senderCheck['valid'],
                'dkim_valid' => $dkimResult['valid'],
                'pdf_attachments_count' => count($pdfAttachments),
                'first_pdf_link' => $pdfAttachments[0]['link'] ?? null,
            ]);

            return response()->json($responseData);

        } catch (\Exception $e) {
            Log::error("Email content verification error: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error verifying email content: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/payments/forward-email",
     *     summary="Forward email to verification email address (client-side forwarding)",
     *     tags={"Payment Verification"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email_raw"},
     *             @OA\Property(property="email_raw", type="string", description="Base64 encoded raw email (from IMAP/POP3 or .eml)"),
     *         )
     *     ),
     *     @OA\Response(response=200, description="Email forwarded to server"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function forwardEmail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email_raw' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Decode raw email
            $rawEmail = base64_decode($request->email_raw);
            
            if (!$rawEmail) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid email format'
                ], 422);
            }

            // Send email to verification email via email service API
            // This will trigger php-verifier.php automatically
            $this->sendEmailToVerifier($rawEmail);

            $verificationEmail = config('mail.verification_email');
            Log::info("Email forwarded to verification email", ['email' => $verificationEmail]);

            return response()->json([
                'success' => true,
                'message' => 'Email forwarded to verification server',
                'data' => [
                    'status' => 'pending_verification',
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Email forwarding error: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error forwarding email: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send email to verification email address via email service API (Postmark/Resend/Mailgun)
     * Uses HTTP API with token authentication
     * Sends raw email as-is to preserve DKIM signature
     * 
     * Note: Uses email service API configured in config/services.php (Postmark/Resend/Mailgun)
     * with API token authentication
     */
    private function sendEmailToVerifier(string $rawEmail): void
    {
        $to = config('mail.verification_email');
        
        if (!$to) {
            Log::error("PAYMENT_VERIFICATION_EMAIL is not set in .env file");
            throw new \Exception('Payment verification email is not configured. Please set PAYMENT_VERIFICATION_EMAIL in .env file.');
        }
        
        $mailer = config('mail.default');
        
        try {
            // Replace To: header in raw email to ensure it goes to verification email
            $lines = explode("\r\n", $rawEmail);
            $newLines = [];
            $toReplaced = false;
            
            foreach ($lines as $line) {
                if (preg_match('/^To:\s*/i', $line) && !$toReplaced) {
                    $newLines[] = "To: $to";
                    $toReplaced = true;
                } else {
                    $newLines[] = $line;
                }
            }
            
            $modifiedRawEmail = implode("\r\n", $newLines);
            
            // Send via email service API based on configured mailer
            if ($mailer === 'postmark') {
                $this->sendViaPostmark($to, $modifiedRawEmail);
            } elseif ($mailer === 'resend') {
                $this->sendViaResend($to, $modifiedRawEmail);
            } elseif ($mailer === 'mailgun') {
                $this->sendViaMailgun($to, $modifiedRawEmail);
            } else {
                // Fallback: use Laravel Mail (may not preserve raw email perfectly)
                $this->sendViaLaravelMail($to, $modifiedRawEmail);
            }
            
            Log::info("Raw email sent to verification email via email service API", [
                'recipient' => $to,
                'raw_email_length' => strlen($rawEmail),
                'mailer' => $mailer
            ]);
            
        } catch (\Exception $e) {
            Log::error("Error sending email to verifier via email service API: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Send raw email via Postmark API
     */
    private function sendViaPostmark(string $to, string $rawEmail): void
    {
        $token = config('services.postmark.token');
        if (!$token) {
            throw new \Exception('POSTMARK_TOKEN is not configured in .env file');
        }
        
        // Postmark API: Send raw email
        $response = Http::withHeaders([
            'X-Postmark-Server-Token' => $token,
            'Content-Type' => 'application/json',
        ])->post('https://api.postmarkapp.com/email/raw', [
            'RawEmail' => base64_encode($rawEmail),
            'To' => $to,
        ]);
        
        if (!$response->successful()) {
            throw new \Exception('Postmark API error: ' . $response->body());
        }
    }
    
    /**
     * Send raw email via Resend API
     */
    private function sendViaResend(string $to, string $rawEmail): void
    {
        $apiKey = config('services.resend.key');
        if (!$apiKey) {
            throw new \Exception('RESEND_KEY is not configured in .env file');
        }
        
        // Resend API: Send raw email
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->post('https://api.resend.com/emails', [
            'to' => $to,
            'raw' => base64_encode($rawEmail),
        ]);
        
        if (!$response->successful()) {
            throw new \Exception('Resend API error: ' . $response->body());
        }
    }
    
    /**
     * Send raw email via Mailgun API
     */
    private function sendViaMailgun(string $to, string $rawEmail): void
    {
        $apiKey = config('services.mailgun.secret');
        $domain = config('services.mailgun.domain');
        if (!$apiKey || !$domain) {
            throw new \Exception('Mailgun API key or domain is not configured in .env file');
        }
        
        // Mailgun API: Send raw email
        $response = Http::asMultipart()->post("https://api.mailgun.net/v3/{$domain}/messages.mime", [
            'to' => $to,
            'message' => base64_encode($rawEmail),
        ])->withBasicAuth('api', $apiKey);
        
        if (!$response->successful()) {
            throw new \Exception('Mailgun API error: ' . $response->body());
        }
    }
    
    /**
     * Fallback: Send via Laravel Mail (may not preserve raw email perfectly)
     */
    private function sendViaLaravelMail(string $to, string $rawEmail): void
    {
        // Extract From email
        $fromEmail = 'noreply@xdexboard.com';
        $fromName = 'XBoard Payment Verification';
        if (preg_match('/^From:\s*(.+)$/mi', $rawEmail, $matches)) {
            $fromHeader = trim($matches[1]);
            if (preg_match('/<([^>]+)>/', $fromHeader, $emailMatch)) {
                $fromEmail = $emailMatch[1];
                $fromName = trim(str_replace('<' . $fromEmail . '>', '', $fromHeader), ' "');
            } elseif (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $fromHeader, $emailMatch)) {
                $fromEmail = $emailMatch[1];
            }
        }
        
        Mail::raw('', function ($message) use ($to, $fromEmail, $fromName, $rawEmail) {
            $message->to($to)
                ->from($fromEmail, $fromName)
                ->subject('Payment Verification Request');
            
            // Try to set raw email body
            $swiftMessage = $message->getSwiftMessage();
            if ($swiftMessage) {
                $swiftMessage->setBody($rawEmail, 'message/rfc822');
            }
        });
    }

    /**
     * Serve a previously saved email PDF attachment by date (UTC YYYY-MM-DD) and token.
     * GET /api/v1/payments/email-attachments/{date}/{token}.pdf
     */
    public function showEmailAttachment(Request $request, string $date, string $token): BinaryFileResponse|JsonResponse
    {
        return $this->serveEmailAttachmentPdf($date, $token, false);
    }

    /**
     * Serve a PDF saved under date/tmp/ (when verify-email-content was called without bank_id).
     * GET /api/v1/payments/email-attachments/{date}/tmp/{token}.pdf
     */
    public function showEmailAttachmentTmp(Request $request, string $date, string $token): BinaryFileResponse|JsonResponse
    {
        return $this->serveEmailAttachmentPdf($date, $token, true);
    }

    private function serveEmailAttachmentPdf(string $date, string $token, bool $tmpSubfolder): BinaryFileResponse|JsonResponse
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['error' => 'Invalid date'], 400);
        }
        if (!preg_match('/^[a-f0-9]{32}$/i', $token)) {
            return response()->json(['error' => 'Invalid token'], 400);
        }
        $path = $tmpSubfolder
            ? 'email_attachments/' . $date . '/tmp/' . $token . '.pdf'
            : 'email_attachments/' . $date . '/' . $token . '.pdf';
        if (!Storage::disk('local')->exists($path)) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $fullPath = Storage::disk('local')->path($path);
        return response()->file($fullPath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="attachment.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Extract PDF attachments from raw MIME email; always saves under email_attachments/{date}/tmp/
     * so that only files promoted to receipt_links_json are moved to date/ and survive tmp cleanup.
     * Returns array of { filename, link } for the client (links point to tmp).
     */
    private function extractAndSavePdfAttachments(string $rawEmail): array
    {
        $result = [];
        $dateFolder = gmdate('Y-m-d');
        $pathPrefix = 'email_attachments/' . $dateFolder . '/tmp';
        Log::info('extractAndSavePdfAttachments: start', ['date_folder' => $dateFolder, 'raw_email_len' => strlen($rawEmail)]);

        $parts = $this->extractMimeParts($rawEmail);
        Log::info('extractAndSavePdfAttachments: MIME parts count', ['count' => count($parts)]);

        foreach ($parts as $i => $part) {
            $contentType = $part['content_type'] ?? '';
            $filename = $part['filename'] ?? '';
            $bodyLen = isset($part['body']) ? strlen($part['body']) : 0;
            Log::info('extractAndSavePdfAttachments: part', [
                'index' => $i,
                'content_type' => substr($contentType, 0, 80),
                'filename' => substr($filename, 0, 80),
                'body_len' => $bodyLen,
                'encoding' => $part['encoding'] ?? '',
            ]);

            if (empty($part['body'])) {
                Log::info('extractAndSavePdfAttachments: skip part (empty body)', ['index' => $i]);
                continue;
            }

            $decoded = $this->decodeMimePartBody($part['body'], $part['encoding'] ?? '');
            if ($decoded === null) {
                Log::warning('extractAndSavePdfAttachments: decode failed', ['index' => $i, 'encoding' => $part['encoding'] ?? '']);
                continue;
            }

            // Consider PDF if Content-Type/filename say so, or if decoded content has PDF magic bytes (some parts have no headers)
            $isPdfByHeader = stripos($contentType, 'application/pdf') !== false
                || (strlen($filename) >= 4 && strtolower(substr($filename, -4)) === '.pdf');
            $isPdfByMagic = strlen($decoded) >= 4 && substr($decoded, 0, 4) === '%PDF';
            $isPdf = $isPdfByHeader || $isPdfByMagic;
            if (!$isPdf) {
                Log::info('extractAndSavePdfAttachments: skip part (not PDF)', ['index' => $i]);
                continue;
            }
            if ($isPdfByMagic && !$isPdfByHeader) {
                Log::info('extractAndSavePdfAttachments: part detected as PDF by magic bytes', ['index' => $i]);
            }

            $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) ?: 'attachment.pdf';
            $token = bin2hex(random_bytes(16));
            $dir = $pathPrefix;
            $path = $dir . '/' . $token . '.pdf';

            Log::info('extractAndSavePdfAttachments: saving', ['path' => $path, 'decoded_len' => strlen($decoded)]);

            try {
                Storage::disk('local')->put($path, $decoded);
                $baseUrl = rtrim(request()->getSchemeAndHttpHost(), '/');
                $baseUrl = preg_replace('/^http:/i', 'https:', $baseUrl);
                $linkPath = 'email-attachments/' . $dateFolder . '/tmp/' . $token . '.pdf';
                $link = $baseUrl . '/api/v1/payments/' . $linkPath;
                $result[] = [
                    'filename' => $this->cleanUtf8String($safeFilename) ?? $safeFilename,
                    'link' => $link,
                ];
                Log::info('extractAndSavePdfAttachments: saved', ['path' => $path, 'link' => $link]);
            } catch (\Throwable $e) {
                Log::warning('extractAndSavePdfAttachments: save failed', ['path' => $path, 'token' => $token, 'error' => $e->getMessage()]);
            }
        }
        return $result;
    }

    /**
     * Extract MIME parts from raw email (recursive for nested multipart); returns array of [ content_type, filename, encoding, body ].
     */
    private function extractMimeParts(string $rawEmail): array
    {
        $parts = [];
        $this->extractMimePartsRecursive($rawEmail, $parts);
        return $parts;
    }

    private function extractMimePartsRecursive(string $rawEmail, array &$parts): void
    {
        $split = preg_split('/\r?\n\r?\n/', $rawEmail, 2);
        $headers = $split[0] ?? '';
        $fullBody = $split[1] ?? '';
        if ($fullBody === '') {
            Log::info('extractMimePartsRecursive: no body after headers');
            return;
        }
        $boundary = null;
        if (preg_match('/boundary\s*=\s*["\']?([^"\'\s;]+)["\']?/i', $headers, $m)) {
            $boundary = trim($m[1], '"\'');
        }
        if ($boundary === null) {
            Log::info('extractMimePartsRecursive: no boundary in headers', ['headers_preview' => substr($headers, 0, 200)]);
            return;
        }
        $segments = preg_split('/\r?\n--' . preg_quote($boundary, '/') . '(?:\r?\n|$)/', $fullBody);
        Log::info('extractMimePartsRecursive: boundary found', ['boundary' => $boundary, 'segments_count' => count($segments)]);
        foreach ($segments as $segment) {
            $segment = trim($segment, "\r\n");
            if ($segment === '' || $segment === '--') {
                continue;
            }
            $subSplit = preg_split('/\r?\n\r?\n/', $segment, 2);
            $partHeaders = $subSplit[0] ?? '';
            $partBody = $subSplit[1] ?? '';
            $contentType = '';
            $filename = '';
            $encoding = '';
            foreach (explode("\n", str_replace("\r", '', $partHeaders)) as $line) {
                if (preg_match('/^Content-Type:\s*(.+)$/i', trim($line), $m)) {
                    $contentType = trim($m[1]);
                    if (preg_match('/name\s*=\s*["\']?([^"\'\s;]+)["\']?/i', $contentType, $n)) {
                        $filename = trim($n[1], '"\'');
                    }
                }
                if (preg_match('/^Content-Disposition:\s*(.+)$/i', trim($line), $m)) {
                    if (preg_match('/filename\s*=\s*["\']?([^"\']+)["\']?/i', $m[1], $n)) {
                        $filename = trim($n[1], '"\'');
                    }
                }
                if (preg_match('/^Content-Transfer-Encoding:\s*(\S+)/i', trim($line), $m)) {
                    $encoding = strtolower(trim($m[1]));
                }
            }
            if (preg_match('/^multipart\//i', $contentType)) {
                $this->extractMimePartsRecursive($partHeaders . "\r\n\r\n" . $partBody, $parts);
                continue;
            }
            $parts[] = [
                'content_type' => $contentType,
                'filename' => $filename,
                'encoding' => $encoding,
                'body' => $partBody,
            ];
        }
    }

    private function decodeMimePartBody(string $body, string $encoding): ?string
    {
        $body = trim($body, "\r\n");
        if ($encoding === 'base64') {
            // Some providers include non-base64 chars/tabs or miss padding in attachment parts.
            $clean = preg_replace('/[^A-Za-z0-9+\/=]/', '', $body) ?? '';
            if ($clean === '') {
                Log::warning('decodeMimePartBody: empty base64 after cleanup');
                return null;
            }
            $mod = strlen($clean) % 4;
            if ($mod !== 0) {
                $clean .= str_repeat('=', 4 - $mod);
            }
            $decoded = base64_decode($clean, true);
            if ($decoded === false) {
                Log::warning('decodeMimePartBody: strict base64 decode failed, trying non-strict', [
                    'raw_len' => strlen($body),
                    'clean_len' => strlen($clean),
                ]);
                $decoded = base64_decode($clean, false);
            }
            return $decoded !== false ? $decoded : null;
        }
        if ($encoding === 'quoted-printable') {
            return quoted_printable_decode($body) ?: null;
        }
        return $body !== '' ? $body : null;
    }

    /**
     * Clean UTF-8 string to prevent JSON encoding errors
     */
    private function cleanUtf8String(?string $str): ?string
    {
        if ($str === null) {
            return null;
        }
        
        // Remove invalid UTF-8 characters
        $str = mb_convert_encoding($str, 'UTF-8', 'UTF-8');
        
        // Remove any remaining invalid characters using regex (FILTER_SANITIZE_STRING is deprecated in PHP 8.1+)
        $str = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $str);
        
        // Ensure valid UTF-8
        if (!mb_check_encoding($str, 'UTF-8')) {
            $str = mb_convert_encoding($str, 'UTF-8', 'UTF-8');
        }
        
        return $str;
    }
}

