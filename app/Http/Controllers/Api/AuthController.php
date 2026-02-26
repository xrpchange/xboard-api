<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EscrowTokenValidationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function __construct(
        private EscrowTokenValidationService $tokenValidationService
    ) {}

    /**
     * @OA\Post(
     *     path="/auth/validate-token",
     *     summary="Validate escrow token",
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"escrow_token"},
     *             @OA\Property(property="escrow_token", type="string", description="Public part of escrow token (JSON)"),
     *             @OA\Property(property="device_id", type="string", description="Device ID (optional)")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Token valid"),
     *     @OA\Response(response=401, description="Invalid escrow token"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    /**
     * Validate escrow token (publicPart)
     * Simple validation endpoint - no Bearer tokens, just validate and cache
     */
    public function validateToken(Request $request): JsonResponse
    {
        $request->validate([
            'escrow_token' => 'required|string',
            'device_id' => 'nullable|string|max:255'
        ]);

        try {
            $publicPart = $request->escrow_token;
            $deviceId = $request->device_id;
            
            Log::info('Escrow token validation request', [
                'public_part_length' => strlen($publicPart),
                'public_part_preview' => substr($publicPart, 0, 200) . '...',
                'device_id' => $deviceId ? substr($deviceId, 0, 32) . '...' : null,
                'ip' => $request->ip()
            ]);
            
            // Validate token (uses cache, calls Core API if needed)
            $validation = $this->tokenValidationService->validateToken($publicPart, $deviceId, $request->ip());
            
            if (!$validation['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid escrow token: ' . ($validation['error'] ?? 'Validation failed')
                ], 401);
            }
            
            Log::info('Escrow token validated successfully', [
                'wallet_address' => substr($validation['token_data']['wallet_address'] ?? '', 0, 10) . '...',
                'currency' => $validation['token_data']['currency'] ?? 'unknown'
            ]);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'valid' => true,
                    'wallet_address' => $validation['token_data']['wallet_address'],
                    'crypto_currency' => $validation['token_data']['currency'],
                    'node_domain' => $validation['token_data']['node_domain'] ?? null,
                    'environment' => $validation['token_data']['environment'] ?? 'sandbox'
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Escrow token validation error', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'public_part_preview' => substr($request->escrow_token ?? '', 0, 200) . '...'
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Token validation error: ' . $e->getMessage()
            ], 500);
        }
    }
} 