<?php

namespace App\Http\Controllers\Api;

use App\Mail\MailboxVerificationCodeMail;
use App\Services\EscrowTokenService;
use App\Services\EscrowTokenValidationService;
use App\Services\TokenCreationRateLimitService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class EscrowTokenController extends BaseApiController
{
    private const CACHE_KEY_PREFIX = 'escrow_verification_code:';

    private const PUZZLE_CACHE_PREFIX = 'escrow_puzzle_token:';

    protected EscrowTokenService $escrowTokenService;

    protected TokenCreationRateLimitService $rateLimitService;

    protected EscrowTokenValidationService $escrowTokenValidation;

    public function __construct(
        EscrowTokenService $escrowTokenService,
        TokenCreationRateLimitService $rateLimitService,
        EscrowTokenValidationService $escrowTokenValidation
    )
    {
        $this->escrowTokenService = $escrowTokenService;
        $this->rateLimitService = $rateLimitService;
        $this->escrowTokenValidation = $escrowTokenValidation;
    }

    /**
     * Get a proof-of-work puzzle for request-verification-code.
     * Client must find puzzle_answer such that SHA256(puzzle_token + puzzle_answer) starts with prefix (hex).
     * One-time use; stops mass bots (each request costs CPU).
     */
    public function getVerificationPuzzle(Request $request): JsonResponse
    {
        $prefixLen = (int) config('rate_limit.verification_puzzle.prefix_hex_length', 4);
        $prefixLen = max(1, min(6, $prefixLen));
        $ttl = (int) config('rate_limit.verification_puzzle.token_ttl_seconds', 300);
        $token = bin2hex(random_bytes(16));
        $prefix = str_repeat('0', $prefixLen);
        Cache::put(self::PUZZLE_CACHE_PREFIX . $token, $prefix, now()->addSeconds($ttl));
        return response()->json([
            'puzzle_token' => $token,
            'prefix' => $prefix,
            'expires_in_seconds' => $ttl,
        ]);
    }

    /**
     * Request verification code for escrow token creation.
     * Requires valid proof-of-work puzzle solution (puzzle_token + puzzle_answer).
     * Sends code to the given email; client must submit this code when calling create.
     */
    public function requestVerificationCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'puzzle_token' => 'required|string|max:128',
            'puzzle_answer' => 'required|string|max:64',
        ]);
        $email = $validated['email'];
        $puzzleError = $this->consumePuzzle(
            $validated['puzzle_token'],
            $validated['puzzle_answer']
        );
        if ($puzzleError !== null) {
            return $puzzleError;
        }

        if (!$this->rateLimitService->isEmailAllowed($email)) {
            return response()->json([
                'message' => 'Only email addresses from supported providers (see Settings) are allowed for token creation.',
                'error_code' => 'email_provider_not_supported',
            ], 400);
        }

        $ip = $request->ip() ?? '0.0.0.0';
        if (!$this->rateLimitService->checkAndIncrementVerificationCodeRequestByIp($ip)) {
            return response()->json([
                'message' => 'Too many verification code requests from your network. Try again later.',
                'error_code' => 'verification_code_ip_limit_exceeded',
            ], 429);
        }
        if (!$this->rateLimitService->checkAndIncrementVerificationCodeRequestByEmail($email)) {
            return response()->json([
                'message' => 'Too many verification codes sent to this email. Try again later.',
                'error_code' => 'verification_code_email_limit_exceeded',
            ], 429);
        }

        $ttlMinutes = config('mail.mailbox_verification.code_ttl_minutes', 30);
        $code = (string) random_int(100000, 999999);
        $cacheKey = self::CACHE_KEY_PREFIX . strtolower($email);
        Cache::put($cacheKey, $code, now()->addMinutes($ttlMinutes));

        try {
            Mail::send(new MailboxVerificationCodeMail($code, $email));
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);
            return $this->errorResponse('Failed to send verification email: ' . $e->getMessage(), 500);
        }

        return response()->json([
            'success' => true,
            'expires_in_minutes' => $ttlMinutes,
        ]);
    }

    /**
     * Request verification code for fee-wallet update flow.
     * Uses the same mailbox code email as token creation, with existing daily external email limit.
     */
    public function requestFeeWalletVerificationCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'puzzle_token' => 'required|string|max:128',
            'puzzle_answer' => 'required|string|max:64',
        ]);
        $email = strtolower(trim((string) $validated['email']));

        $puzzleError = $this->consumePuzzle(
            $validated['puzzle_token'],
            $validated['puzzle_answer']
        );
        if ($puzzleError !== null) {
            return $puzzleError;
        }

        if (!$this->rateLimitService->checkAndIncrementVerificationCodeRequestByEmail($email)) {
            return response()->json([
                'message' => 'Too many verification codes sent to this email. Try again later.',
                'error_code' => 'verification_code_email_limit_exceeded',
            ], 429);
        }

        if (!$this->incrementEmailTestCounter($email)) {
            return response()->json([
                'message' => 'Daily limit for external emails reached for this address.',
                'error_code' => 'external_email_daily_limit_reached',
            ], 429);
        }

        $ttlMinutes = (int) config('mail.mailbox_verification.code_ttl_minutes', 30);
        $code = (string) random_int(100000, 999999);
        $cacheKey = self::CACHE_KEY_PREFIX . 'fee_wallet:' . $email;
        Cache::put($cacheKey, $code, now()->addMinutes($ttlMinutes));

        try {
            Mail::send(new MailboxVerificationCodeMail($code, $email));
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);
            return $this->errorResponse('Failed to send verification email: ' . $e->getMessage(), 500);
        }

        return response()->json([
            'success' => true,
            'expires_in_minutes' => $ttlMinutes,
        ]);
    }

    /**
     * Set one Core fee-wallet config parameter (clean/dirty) after email verification and admin password check in Core.
     */
    public function setFeeWalletAddress(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'verification_code' => 'required|string|size:6',
            'admin_password' => 'required|string|min:1|max:256',
            'wallet_param' => 'required|string|regex:/^[a-z0-9_]+$/',
            'wallet_address' => 'nullable|string|max:512',
        ]);

        $email = strtolower(trim((string) $validated['email']));
        $cacheKey = self::CACHE_KEY_PREFIX . 'fee_wallet:' . $email;
        $cachedCode = Cache::get($cacheKey);
        if ($cachedCode === null || $cachedCode !== $validated['verification_code']) {
            return response()->json([
                'message' => 'Invalid or expired verification code. Request a new code.',
                'error_code' => 'invalid_verification_code',
            ], 422);
        }

        $escrowToken = (string) ($request->header('X-Escrow-Token') ?? '');
        if ($escrowToken === '') {
            return response()->json([
                'message' => 'Escrow token header is required.',
                'error_code' => 'escrow_token_missing',
            ], 401);
        }

        [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($escrowToken);
        $walletParam = strtolower(trim((string) $validated['wallet_param']));
        $walletAddress = trim((string) ($validated['wallet_address'] ?? ''));

        $req = Http::timeout((int) config('services.core.timeout', 10));
        if ($coreKey !== '') {
            $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
        }

        try {
            $coreResponse = $req->post(rtrim($coreUrl, '/') . '/api/admin/set-fee-wallet', [
                'admin_email' => $email,
                'admin_password' => $validated['admin_password'],
                'wallet_param' => $walletParam,
                'wallet_value' => $walletAddress,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Core is unavailable: ' . $e->getMessage(),
                'error_code' => 'core_unavailable',
            ], 502);
        }

        $payload = $coreResponse->json();
        if (!$coreResponse->successful()) {
            return response()->json([
                'message' => $payload['message'] ?? 'Failed to set fee wallet parameter.',
                'error_code' => $payload['error_code'] ?? 'fee_wallet_update_failed',
            ], $coreResponse->status());
        }

        Cache::forget($cacheKey);

        return response()->json([
            'success' => true,
            'wallet_param' => $walletParam,
            'wallet_value' => $walletAddress,
        ]);
    }

    /**
     * Change Core admin password after mailbox verification.
     */
    public function changeAdminPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'verification_code' => 'required|string|size:6',
            'current_admin_password' => 'required|string|min:1|max:256',
            'new_admin_password' => 'required|string|min:1|max:256',
        ]);

        $email = strtolower(trim((string) $validated['email']));
        $cacheKey = self::CACHE_KEY_PREFIX . 'fee_wallet:' . $email;
        $cachedCode = Cache::get($cacheKey);
        if ($cachedCode === null || $cachedCode !== $validated['verification_code']) {
            return response()->json([
                'message' => 'Invalid or expired verification code. Request a new code.',
                'error_code' => 'invalid_verification_code',
            ], 422);
        }

        $escrowToken = (string) ($request->header('X-Escrow-Token') ?? '');
        if ($escrowToken === '') {
            return response()->json([
                'message' => 'Escrow token header is required.',
                'error_code' => 'escrow_token_missing',
            ], 401);
        }

        [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($escrowToken);

        $req = Http::timeout((int) config('services.core.timeout', 10));
        if ($coreKey !== '') {
            $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
        }

        try {
            $coreResponse = $req->post(rtrim($coreUrl, '/') . '/api/admin/change-password', [
                'admin_email' => $email,
                'current_admin_password' => $validated['current_admin_password'],
                'new_admin_password' => $validated['new_admin_password'],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Core is unavailable: ' . $e->getMessage(),
                'error_code' => 'core_unavailable',
            ], 502);
        }

        $payload = $coreResponse->json();
        if (!$coreResponse->successful()) {
            return response()->json([
                'message' => $payload['message'] ?? 'Failed to change admin password.',
                'error_code' => $payload['error_code'] ?? 'admin_password_change_failed',
            ], $coreResponse->status());
        }

        Cache::forget($cacheKey);

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Get available cryptocurrencies from Core
     */
    public function getAvailableCryptos(): JsonResponse
    {
        try {
            $result = $this->escrowTokenService->getAvailableCryptos();
            return response()->json($result);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * Create escrow token (Stage 1 - Open Part)
     * 
     * @OA\Post(
     *     path="/api/v1/escrow-tokens/create",
     *     tags={"Escrow Tokens"},
     *     summary="Create escrow token (Stage 1)",
     *     description="Creates escrow token and returns open part. Stage 1 of two-stage process.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"crypto_type", "email"},
     *             @OA\Property(property="crypto_type", type="string", example="usdt_tron", description="Cryptocurrency type; validated by Core"),
     *             @OA\Property(property="amount", type="number", example=10, description="Optional amount"),
     *             @OA\Property(property="email", type="string", example="user@gmail.com", description="Email from a supported provider (for rate limiting)")
     *         )
     *     ),
     *     @OA\Response(response=429, description="Rate limit exceeded (email or IP)"),
     *     @OA\Response(response=400, description="Email provider not supported"),
     *     @OA\Response(
     *         response=201,
     *         description="Token created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="escrow_token", type="string", description="Open part of token (JSON string)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="error_code", type="string")
     *         )
     *     )
     * )
     */
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'crypto_type' => 'required|string',
            'amount' => 'nullable|numeric|min:0',
            'network' => 'nullable|string|max:32',
            'escrow_multisig_2_of_3_required' => 'nullable|boolean',
            'email' => 'required|string|email',
            'verification_code' => 'required|string|size:6',
        ]);

        $email = $validated['email'];
        $code = $validated['verification_code'];
        $cacheKey = self::CACHE_KEY_PREFIX . strtolower($email);
        $cachedCode = Cache::get($cacheKey);

        if ($cachedCode === null || $cachedCode !== $code) {
            return response()->json([
                'message' => 'Invalid or expired verification code. Request a new code.',
                'error_code' => 'invalid_verification_code',
            ], 422);
        }

        if (!$this->rateLimitService->isEmailAllowed($email)) {
            return response()->json([
                'message' => 'Only email addresses from supported providers (see Settings) are allowed for token creation.',
                'error_code' => 'email_provider_not_supported',
            ], 400);
        }

        $host = $request->getHost();
        $environment = preg_match('/^(sandbox\.|dev\.)/', $host) ? 'sandbox' : 'production';
        $ip = $request->ip() ?? '0.0.0.0';

        $emailLimit = $this->rateLimitService->getEmailLimit($environment);
        $emailCount = $this->rateLimitService->getEmailCount($email, $environment);
        if ($emailCount >= $emailLimit) {
            return response()->json([
                'message' => "Token creation limit for this email: {$emailCount}/{$emailLimit} per month ({$environment}). Try again next month.",
                'error_code' => 'email_quota_exceeded',
            ], 429);
        }

        $ipLimit = $this->rateLimitService->getIpLimit();
        $ipCount = $this->rateLimitService->getIpCount($ip);
        if ($ipCount >= $ipLimit) {
            return response()->json([
                'message' => "Token creation limit for this IP: {$ipCount}/{$ipLimit} per day. Try again tomorrow.",
                'error_code' => 'ip_quota_exceeded',
            ], 429);
        }

        try {
            $network = isset($validated['network']) ? trim((string) $validated['network']) : null;
            $result = $this->escrowTokenService->createToken(
                $validated['crypto_type'],
                (float)($validated['amount'] ?? 0.0),
                $network !== '' ? $network : null,
                (bool)($validated['escrow_multisig_2_of_3_required'] ?? false)
            );

            Cache::forget($cacheKey);
            $this->rateLimitService->incrementEmail($email, $environment);
            $this->rateLimitService->incrementIp($ip);

            // Mark email as verified for future test/emergency notifications.
            $this->markEmailAsVerified($email);

            return response()->json($result, 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * Store/update email in verified_emails after successful token creation.
     */
    private function markEmailAsVerified(string $email): void
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '') {
            return;
        }

        $now = now();
        $existing = \DB::table('verified_emails')->where('email', $normalized)->first();
        if ($existing === null) {
            \DB::table('verified_emails')->insert([
                'email' => $normalized,
                'first_verified_at' => $now,
                'last_verified_at' => $now,
            ]);
        } else {
            \DB::table('verified_emails')
                ->where('email', $normalized)
                ->update(['last_verified_at' => $now]);
        }
    }

    /**
     * Validate and cache escrow token
     * 
     * Empty endpoint that requires token validation.
     * Token is validated through ValidateEscrowToken middleware, which automatically
     * saves it to escrow_token_cache. This endpoint just returns success.
     * 
     * @OA\Post(
     *     path="/api/v1/escrow-tokens/validate-cache",
     *     tags={"Escrow Tokens"},
     *     summary="Validate and cache escrow token",
     *     description="Validates escrow token and saves it to cache. Token must be sent in X-Escrow-Token header.",
     *     security={{"escrowToken": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Token validated and cached successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Invalid token",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string")
     *         )
     *     )
     * )
     */
    public function validateCache(Request $request): JsonResponse
    {
        // Token is already validated by ValidateEscrowToken middleware
        // and saved to escrow_token_cache
        // Just return success
        
        return response()->json(['success' => true]);
    }

    private function consumePuzzle(string $puzzleToken, string $puzzleAnswer): ?JsonResponse
    {
        $cacheKey = self::PUZZLE_CACHE_PREFIX . $puzzleToken;
        $storedPrefix = Cache::get($cacheKey);
        if ($storedPrefix === null) {
            return response()->json([
                'message' => 'Invalid or expired puzzle. Request a new one.',
                'error_code' => 'puzzle_invalid_or_expired',
            ], 400);
        }

        $hash = hash('sha256', $puzzleToken . $puzzleAnswer);
        if (substr($hash, 0, strlen((string) $storedPrefix)) !== $storedPrefix) {
            return response()->json([
                'message' => 'Invalid puzzle solution.',
                'error_code' => 'puzzle_invalid',
            ], 400);
        }

        Cache::forget($cacheKey);
        return null;
    }

    /**
     * Existing per-recipient daily limit for external emails (MAIL_TEST_EMAIL_DAILY_LIMIT).
     */
    private function incrementEmailTestCounter(string $email): bool
    {
        $limit = (int) config('mail.test_email_daily_limit', 30);
        if ($limit <= 0) {
            return true;
        }

        $normalized = strtolower(trim($email));
        if ($normalized === '') {
            return false;
        }

        $today = now()->toDateString();

        return DB::transaction(function () use ($normalized, $today, $limit): bool {
            $row = DB::table('email_test_counters')
                ->where('email', $normalized)
                ->where('day', $today)
                ->lockForUpdate()
                ->first();

            if ($row !== null && (int) $row->sent_count >= $limit) {
                return false;
            }

            if ($row === null) {
                DB::table('email_test_counters')->insert([
                    'email' => $normalized,
                    'day' => $today,
                    'sent_count' => 1,
                    'last_sent_at' => now(),
                ]);
            } else {
                DB::table('email_test_counters')
                    ->where('id', $row->id)
                    ->update([
                        'sent_count' => (int) $row->sent_count + 1,
                        'last_sent_at' => now(),
                    ]);
            }

            return true;
        });
    }
}

