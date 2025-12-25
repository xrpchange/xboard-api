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
     * Verify email sender (checks if domain is in allowed banks)
     */
    private function verifySender(string $rawEmail): array
    {
        $result = [
            'valid' => false,
            'from' => null,
            'domain' => null,
            'error' => null,
        ];

        // Use extractSenderInfo to get domain
        $senderInfo = $this->extractSenderInfo($rawEmail);
        
        if ($senderInfo['domain']) {
            $result['domain'] = $senderInfo['domain'];
            $result['from'] = $senderInfo['from'];
            
            // Check if domain is in allowed banks
            foreach ($this->allowedBanks as $bankDomain) {
                if ($senderInfo['domain'] === $bankDomain || 
                    substr($senderInfo['domain'], -strlen($bankDomain) - 1) === '.' . $bankDomain) {
                    $result['valid'] = true;
                    return $result;
                }
            }
            
            $result['error'] = "Domain '{$senderInfo['domain']}' not in allowed banks";
        } else {
            $result['error'] = 'Could not extract sender domain from email';
        }

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
     *             @OA\Property(property="email_raw", type="string", description="Base64 encoded raw email (from OAuth or .eml)"),
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

            // Log what we received
            Log::info("verifyEmailContent: received decoded email", [
                'decoded_length' => strlen($rawEmail),
                'starts_with' => substr($rawEmail, 0, 100),
                'has_from' => strpos($rawEmail, 'From:') !== false,
                'has_dkim' => strpos($rawEmail, 'DKIM-Signature') !== false,
            ]);

            // Extract sender domain and email
            $senderInfo = $this->extractSenderInfo($rawEmail);
            
            // Verify DKIM signature
            $dkimResult = $this->verifyDKIM($rawEmail);

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
                    'dkim_error' => $dkimResult['error'] ? $this->cleanUtf8String($dkimResult['error']) : null,
                ]
            ];
            
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
     *         @OA\Schema(type="string", enum={"yandex", "gmail", "mailru", "yahoo"})
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
            'provider' => 'required|in:yandex,gmail,mailru,yahoo',
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
            
            if (in_array($provider, ['yandex', 'gmail', 'mailru', 'yahoo'])) {
                // All these providers use XOAUTH2 for IMAP
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
     *         @OA\Schema(type="string", enum={"yandex", "gmail", "mailru", "yahoo"})
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
            'provider' => 'required|in:yandex,gmail,mailru,yahoo',
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
            
            if (in_array($provider, ['yandex', 'gmail', 'mailru', 'yahoo'])) {
                // All these providers use XOAUTH2 for IMAP
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
                'host' => 'imap.yandex.com',
                'port' => 993,
            ],
            'gmail' => [
                'host' => 'imap.gmail.com',
                'port' => 993,
            ],
            'mailru' => [
                'host' => 'imap.mail.ru',
                'port' => 993,
            ],
            'yahoo' => [
                'host' => 'imap.mail.yahoo.com',
                'port' => 993,
            ],
        ];

        return $settings[$provider] ?? null;
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

