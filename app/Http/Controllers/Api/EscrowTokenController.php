<?php

namespace App\Http\Controllers\Api;

use App\Services\EscrowTokenService;
use App\Services\TokenCreationRateLimitService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EscrowTokenController extends BaseApiController
{
    protected EscrowTokenService $escrowTokenService;

    protected TokenCreationRateLimitService $rateLimitService;

    public function __construct(EscrowTokenService $escrowTokenService, TokenCreationRateLimitService $rateLimitService)
    {
        $this->escrowTokenService = $escrowTokenService;
        $this->rateLimitService = $rateLimitService;
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
     *             @OA\Property(property="crypto_type", type="string", example="xrp", description="Cryptocurrency type (xrp, btc, xmr)"),
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
            'crypto_type' => 'required|string|in:xrp,btc,xmr',
            'amount' => 'nullable|numeric|min:0',
            'email' => 'required|string|email',
        ]);

        $email = $validated['email'];
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

