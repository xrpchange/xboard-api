<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\ImapMonitorService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

/**
 * @OA\Tag(
 *     name="Payment Verification",
 *     description="Endpoints for verifying payment emails with DKIM"
 * )
 */
class PaymentVerificationController extends Controller
{
    /**
     * Allowed bank domains
     */
    private array $allowedBanks = [
        'sberbank.ru',
        'tinkoff.ru',
        'alfabank.ru',
        'vtb.ru',
        'gazprombank.ru',
        'raiffeisen.ru',
        'rosbank.ru',
        'otpbank.ru',
        'uralsib.ru',
        'banki.ru',
    ];

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
     *             @OA\Property(property="format", type="string", enum={"raw", "eml", "plain"}, description="Format: raw (OAuth), eml (file), plain (copy-paste)"),
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
                    // OAuth raw email (base64 encoded)
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

            // Verify sender (bank domain)
            $senderCheck = $this->verifySender($rawEmail);
            if (!$senderCheck['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email is not from an allowed bank',
                    'error' => $senderCheck['error']
                ], 422);
            }

            // Verify DKIM signature (skip for plain format)
            $dkimResult = ['valid' => false, 'signature' => null, 'domain' => null, 'error' => null];
            if ($format !== 'plain') {
                $dkimResult = $this->verifyDKIM($rawEmail);
                if (!$dkimResult['valid']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'DKIM verification failed',
                        'error' => $dkimResult['error'],
                        'hint' => 'Try forwarding email as attachment or use OAuth method'
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
     * Verify email sender
     */
    private function verifySender(string $rawEmail): array
    {
        $result = [
            'valid' => false,
            'from' => null,
            'domain' => null,
            'error' => null,
        ];

        if (preg_match('/^From:\s*(.+)$/mi', $rawEmail, $matches)) {
            $from = $matches[1];
            
            if (preg_match('/@([^\s>]+)/', $from, $domainMatch)) {
                $domain = strtolower(trim($domainMatch[1]));
                $result['domain'] = $domain;
                $result['from'] = $from;
                
                foreach ($this->allowedBanks as $bankDomain) {
                    if ($domain === $bankDomain || 
                        substr($domain, -strlen($bankDomain) - 1) === '.' . $bankDomain) {
                        $result['valid'] = true;
                        return $result;
                    }
                }
                
                $result['error'] = "Domain '{$domain}' not in allowed banks";
            }
        }

        return $result;
    }

    /**
     * Verify DKIM signature
     */
    private function verifyDKIM(string $rawEmail): array
    {
        $result = [
            'valid' => false,
            'signature' => null,
            'domain' => null,
            'error' => null,
        ];

        // Extract DKIM signature
        if (preg_match('/^DKIM-Signature:\s*(.+)$/mi', $rawEmail, $matches)) {
            $result['signature'] = $matches[1];
            
            if (preg_match('/d=([^;]+)/', $matches[1], $domainMatch)) {
                $result['domain'] = trim($domainMatch[1]);
            }
        } else {
            $result['error'] = 'DKIM-Signature header not found';
            return $result;
        }

        // Verify using opendkim-testmsg
        $tempFile = tempnam(sys_get_temp_dir(), 'dkim_');
        file_put_contents($tempFile, $rawEmail);
        
        $command = "opendkim-testmsg -f {$tempFile} 2>&1";
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        
        unlink($tempFile);
        
        if ($returnCode === 0) {
            $result['valid'] = true;
        } else {
            $result['error'] = implode("\n", $output);
        }

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
     *     path="/api/v1/payments/forward-email",
     *     summary="Forward email to check@xdexboard.com (client-side forwarding)",
     *     tags={"Payment Verification"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email_raw"},
     *             @OA\Property(property="email_raw", type="string", description="Base64 encoded raw email (from OAuth or .eml)"),
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

            // Send email to check@xdexboard.com via SMTP
            // This will trigger php-verifier.php automatically
            $this->sendEmailToVerifier($rawEmail);

            Log::info("Email forwarded to check@xdexboard.com");

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
     * Send email to check@xdexboard.com via SMTP
     */
    private function sendEmailToVerifier(string $rawEmail): void
    {
        // Use Laravel Mail or direct SMTP
        // For now, we'll use mail() function or queue job
        // TODO: Implement proper SMTP sending
        
        $to = 'check@xdexboard.com';
        $subject = 'Payment Verification Request';
        
        // Parse raw email to extract headers
        $headers = [];
        $bodyStart = strpos($rawEmail, "\r\n\r\n");
        if ($bodyStart !== false) {
            $headerSection = substr($rawEmail, 0, $bodyStart);
            $body = substr($rawEmail, $bodyStart + 4);
            
            // Extract From header
            if (preg_match('/^From:\s*(.+)$/mi', $headerSection, $matches)) {
                $headers[] = 'From: ' . $matches[1];
            }
            
            // Send email (this will be processed by php-verifier.php)
            mail($to, $subject, $body, implode("\r\n", $headers));
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/payments/emails",
     *     summary="Get email list from OAuth-connected account",
     *     tags={"Payment Verification"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="provider",
     *         in="query",
     *         required=true,
     *         description="Email provider",
     *         @OA\Schema(type="string", enum={"yandex", "gmail", "outlook"})
     *     ),
     *     @OA\Parameter(
     *         name="email",
     *         in="query",
     *         required=true,
     *         description="User email address",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="access_token",
     *         in="query",
     *         required=true,
     *         description="OAuth access token",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Maximum number of emails to return",
     *         @OA\Schema(type="integer", default=50)
     *     ),
     *     @OA\Parameter(
     *         name="offset",
     *         in="query",
     *         required=false,
     *         description="Offset for pagination",
     *         @OA\Schema(type="integer", default=0)
     *     ),
     *     @OA\Response(response=200, description="Email list retrieved"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function getEmailList(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'provider' => 'required|in:yandex,gmail,outlook',
            'email' => 'required|string', // Email from OAuth (already known on client)
            'access_token' => 'required|string',
            'limit' => 'nullable|integer|min:1|max:100',
            'offset' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            Log::error("Email list validation failed", [
                'errors' => $validator->errors()->toArray(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $provider = $request->provider;
            $email = $request->email; // Email from OAuth (already known on client)
            $accessToken = $request->access_token;
            $limit = $request->limit ?? 50;
            $offset = $request->offset ?? 0;
            
            Log::info("getEmailList request data", [
                'provider' => $provider,
                'email' => $email,
                'has_access_token' => !empty($accessToken),
                'limit' => $limit,
                'offset' => $offset
            ]);

            // Get IMAP settings for provider
            $imapSettings = $this->getImapSettings($provider);
            
            if (!$imapSettings) {
                return response()->json([
                    'success' => false,
                    'message' => "Provider '{$provider}' not supported"
                ], 422);
            }

            // Connect via IMAP using OAuth token
            $imapService = new ImapMonitorService();
            
            if ($provider === 'yandex') {
                // Use webklex/php-imap library which supports XOAUTH2
                $connected = $imapService->connectWithOAuth(
                    $email, // Email from OAuth (obtained during authorization)
                    $accessToken,
                    $imapSettings['host'],
                    $imapSettings['port']
                );
            } else {
                // For Gmail/Outlook, might need different approach
                // For now, return error
                return response()->json([
                    'success' => false,
                    'message' => "OAuth IMAP not yet implemented for {$provider}"
                ], 422);
            }

            if (!$connected) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to connect to email server'
                ], 422);
            }

            // Get email list
            $emails = $imapService->getEmailList($limit, $offset);
            
            $imapService->disconnect();

            return response()->json([
                'success' => true,
                'data' => [
                    'emails' => $emails,
                    'count' => count($emails),
                    'limit' => $limit,
                    'offset' => $offset,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Error getting email list: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error getting email list: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/payments/emails/{messageId}",
     *     summary="Get raw email by message ID",
     *     tags={"Payment Verification"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="messageId",
     *         in="path",
     *         required=true,
     *         description="Email message ID or email number",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="provider",
     *         in="query",
     *         required=true,
     *         description="Email provider",
     *         @OA\Schema(type="string", enum={"yandex", "gmail", "outlook"})
     *     ),
     *     @OA\Parameter(
     *         name="email",
     *         in="query",
     *         required=true,
     *         description="User email address",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="access_token",
     *         in="query",
     *         required=true,
     *         description="OAuth access token",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(response=200, description="Raw email retrieved"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function getRawEmail(Request $request, string $messageId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'provider' => 'required|in:yandex,gmail,outlook',
            'email' => 'required|string', // Email from OAuth (already known on client)
            'access_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $provider = $request->provider;
            $email = $request->email; // Email from OAuth (already known on client)
            $accessToken = $request->access_token;

            // Get IMAP settings for provider
            $imapSettings = $this->getImapSettings($provider);
            
            if (!$imapSettings) {
                return response()->json([
                    'success' => false,
                    'message' => "Provider '{$provider}' not supported"
                ], 422);
            }

            // Connect via IMAP using OAuth token
            $imapService = new ImapMonitorService();
            
            if ($provider === 'yandex') {
                // Use webklex/php-imap library which supports XOAUTH2
                $connected = $imapService->connectWithOAuth(
                    $email, // Email from OAuth (obtained during authorization)
                    $accessToken,
                    $imapSettings['host'],
                    $imapSettings['port']
                );
            } else {
                return response()->json([
                    'success' => false,
                    'message' => "OAuth IMAP not yet implemented for {$provider}"
                ], 422);
            }

            if (!$connected) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to connect to email server'
                ], 422);
            }

            // Get raw email
            $rawEmail = null;
            if (is_numeric($messageId)) {
                $rawEmail = $imapService->getRawEmailByNum((int)$messageId);
            } else {
                $rawEmail = $imapService->getRawEmailById($messageId);
            }
            
            $imapService->disconnect();

            if (!$rawEmail) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email not found'
                ], 404);
            }

            // Return base64 encoded raw email
            return response()->json([
                'success' => true,
                'data' => [
                    'message_id' => $messageId,
                    'email_raw' => base64_encode($rawEmail),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Error getting raw email: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error getting raw email: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get IMAP settings for email provider
     */
    private function getImapSettings(string $provider): ?array
    {
        $settings = [
            'yandex' => [
                'host' => 'imap.yandex.com', // Use imap.yandex.com instead of imap.yandex.ru
                'port' => 993,
            ],
            'gmail' => [
                'host' => 'imap.gmail.com',
                'port' => 993,
            ],
            'outlook' => [
                'host' => 'outlook.office365.com',
                'port' => 993,
            ],
        ];

        return $settings[$provider] ?? null;
    }
}

