<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CollateralTokenService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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
            'escrow_token' => 'required|string'
        ]);

        try {
            // Создать Bearer токен (включает проверку баланса через ядро)
            $bearerToken = $this->collateralTokenService->createBearerToken($request->escrow_token, $request->ip());
            
            // Расшифровать токен для получения данных
            $tokenData = $this->collateralTokenService->decryptToken($request->escrow_token);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'bearer_token' => $bearerToken,
                    'wallet_address' => $tokenData['wallet_address'],
                    'crypto_currency' => $tokenData['currency']
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid escrow token'
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

        // Валидировать Bearer токен
        $userToken = $this->collateralTokenService->validateBearerToken($bearerToken);
        
        if (!$userToken) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid bearer token'
            ], 401);
        }

        // Расшифровать залоговый токен для получения данных
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
} 