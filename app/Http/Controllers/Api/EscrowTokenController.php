<?php

namespace App\Http\Controllers\Api;

use App\Mail\MailboxVerificationCodeMail;
use App\Services\EscrowTokenService;
use App\Services\TokenCreationRateLimitService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class EscrowTokenController extends BaseApiController
{
    private const CACHE_KEY_PREFIX = 'escrow_verification_code:';

    private const PUZZLE_CACHE_PREFIX = 'escrow_puzzle_token:';

    protected EscrowTokenService $escrowTokenService;

    protected TokenCreationRateLimitService $rateLimitService;

    public function __construct(EscrowTokenService $escrowTokenService, TokenCreationRateLimitService $rateLimitService)
    {
        $this->escrowTokenService = $escrowTokenService;
        $this->rateLimitService = $rateLimitService;
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
        $puzzleToken = $validated['puzzle_token'];
        $puzzleAnswer = $validated['puzzle_answer'];

        $cacheKey = self::PUZZLE_CACHE_PREFIX . $puzzleToken;
        $storedPrefix = Cache::get($cacheKey);
        if ($storedPrefix === null) {
            return response()->json([
                'message' => 'Invalid or expired puzzle. Request a new one.',
                'error_code' => 'puzzle_invalid_or_expired',
            ], 400);
        }
        $hash = hash('sha256', $puzzleToken . $puzzleAnswer);
        if (substr($hash, 0, strlen($storedPrefix)) !== $storedPrefix) {
            return response()->json([
                'message' => 'Invalid puzzle solution.',
                'error_code' => 'puzzle_invalid',
            ], 400);
        }
        Cache::forget($cacheKey);

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
            $result = $this->escrowTokenService->createToken(
                $validated['crypto_type'],
                (float)($validated['amount'] ?? 0.0)
            );

            Cache::forget($cacheKey);
            $this->rateLimitService->incrementEmail($email, $environment);
            $this->rateLimitService->incrementIp($ip);

            return response()->json($result, 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
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
}

