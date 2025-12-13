<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CollateralTokenService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function __construct(
        private CollateralTokenService $collateralTokenService
    ) {}

    /**
     * @OA\Post(
     *     path="/auth/bearer-token",
     *     summary="Get bearer token",
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"escrow_token"},
     *             @OA\Property(
     *                 property="escrow_token", 
     *                 type="string", 
     *                 example="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
     *                 description="Escrow token for authentication"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Bearer token received",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="bearer_token", type="string", example="1|abc123..."),
     *                 @OA\Property(property="wallet_address", type="string", example="r123..."),
     *                 @OA\Property(property="crypto_currency", type="string", example="XRP")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Invalid escrow token"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function getBearerToken(Request $request): JsonResponse
    {
        $request->validate([
            'escrow_token' => 'required|string',
            'device_id' => 'required|string|max:255'
            // NOTE: node_domain is not needed - it's already in escrow token
        ]);

        try {
            // Client sends publicPart (JSON string), not full token
            $publicPart = $request->escrow_token;
            $deviceId = $request->device_id;
            // nodeDomain will be extracted from escrow token if not provided
            $nodeDomain = $request->node_domain ?? null;
            
            Log::info('Bearer token request received', [
                'public_part_length' => strlen($publicPart),
                'public_part_preview' => substr($publicPart, 0, 200) . '...',
                'device_id' => substr($deviceId, 0, 32) . '...',
                'node_domain' => $nodeDomain,
                'ip' => $request->ip()
            ]);
            
            // Create Bearer token (includes balance check via core)
            // IMPORTANT: Each node gets its own Bearer token (one token per node)
            $bearerTokenResult = $this->collateralTokenService->createBearerTokenWithExpiry($publicPart, $request->ip(), $deviceId, $nodeDomain);
            
            // Decrypt token to get data
            $tokenData = $this->collateralTokenService->decryptToken($publicPart);
            
            Log::info('Bearer token created successfully', [
                'wallet_address' => substr($tokenData['wallet_address'], 0, 10) . '...',
                'currency' => $tokenData['currency'],
                'environment' => $tokenData['environment'] ?? 'unknown',
                'expires_at' => $bearerTokenResult['expires_at']
            ]);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'bearer_token' => $bearerTokenResult['token'],
                    'expires_at' => $bearerTokenResult['expires_at'],
                    'wallet_address' => $tokenData['wallet_address'],
                    'crypto_currency' => $tokenData['currency']
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Bearer token creation failed', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'public_part_preview' => substr($request->escrow_token ?? '', 0, 200) . '...',
                'public_part_length' => strlen($request->escrow_token ?? ''),
                'device_id' => substr($deviceId ?? '', 0, 16) . '...',
                'node_domain' => $nodeDomain
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid escrow token: ' . $e->getMessage()
            ], 401);
        }
    }

    /**
     * @OA\Post(
     *     path="/auth/validate-token",
     *     summary="Validate bearer token",
     *     tags={"Authentication"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Token is valid",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="valid", type="boolean", example=true),
     *                 @OA\Property(property="wallet_address", type="string", example="r123..."),
     *                 @OA\Property(property="crypto_currency", type="string", example="XRP")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Invalid bearer token")
     * )
     */
    public function validateBearerToken(Request $request): JsonResponse
    {
        $bearerToken = $request->bearerToken();

        if (!$bearerToken) {
            return response()->json([
                'success' => false,
                'message' => 'Bearer token not provided'
            ], 401);
        }

        // Validate Bearer token
        $userToken = $this->collateralTokenService->validateBearerToken($bearerToken);
        
        if (!$userToken) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid bearer token'
            ], 401);
        }

        // Decrypt collateral token to get data
        $tokenData = $this->collateralTokenService->decryptToken($userToken->collateral_token);

        return response()->json([
            'success' => true,
            'data' => [
                'valid' => true,
                'wallet_address' => $tokenData['wallet_address'],
                'crypto_currency' => $tokenData['currency']
            ]
        ]);
    }

    /**
     * REMOVED: refreshBearerToken endpoint
     * 
     * Security reason: If Bearer token is stolen, attacker could refresh it indefinitely.
     * Instead, client should request new Bearer token using escrow token via /auth/bearer-token
     * This requires escrow token which is stored only on client device.
     */
    
    /**
     * Delete bearer tokens for a specific node
     * Called when node is removed or untrusted
     * IMPORTANT: This prevents memory leaks and ensures clean state for testing
     */
    public function deleteBearerTokensForNode(Request $request): JsonResponse
    {
        $request->validate([
            'node_domain' => 'required|string|max:255'
        ]);

        try {
            $nodeDomain = $request->node_domain;
            
            $deletedCount = $this->collateralTokenService->deleteBearerTokensForNode($nodeDomain);
            
            Log::info('Bearer tokens deleted for node', [
                'node_domain' => $nodeDomain,
                'deleted_count' => $deletedCount
            ]);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'node_domain' => $nodeDomain,
                    'deleted_count' => $deletedCount
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete bearer tokens for node', [
                'error' => $e->getMessage(),
                'node_domain' => $request->node_domain ?? 'unknown'
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete bearer tokens: ' . $e->getMessage()
            ], 500);
        }
    }
} 