<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EscrowTokenService;
use App\Services\BearerTokenService;
use App\Exceptions\XBoardException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

/**
 * @OA\Info(
 *     title="XBoard API",
 *     version="1.0.0",
 *     description="API for P2P cryptocurrency exchange with escrow guarantee"
 * )
 * @OA\Server(
 *     url="http://sandbox.api.xrpchange.site/api/v1",
 *     description="Sandbox server (for testing)"
 * )
 * @OA\Server(
 *     url="http://api.xrpchange.site/api/v1",
 *     description="Production server"
 * )
 * @OA\Server(
 *     url="http://localhost:8000/api/v1",
 *     description="Local server"
 * )
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer"
 * )
 */

class AuthController extends Controller
{
    public function __construct(
        private EscrowTokenService $escrowTokenService,
        private BearerTokenService $bearerTokenService
    ) {}

    /**
     * @OA\Post(
     *     path="/auth/bearer-token",
     *     summary="Get bearer token",
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="escrow_token", type="string", example="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Bearer token received",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="bearer_token", type="string", example="1|abc123..."),
     *                 @OA\Property(property="user", ref="#/components/schemas/User")
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
            // Validate escrow token
            $user = $this->validateEscrowToken($request->escrow_token);
            
            // Generate bearer token
            $token = $user->createToken('api-token')->plainTextToken;
            
            return response()->json([
                'success' => true,
                'data' => [
                    'bearer_token' => $token,
                    'user' => $user
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
     * Validate escrow token and return user
     */
    private function validateEscrowToken(string $escrowToken): User
    {
        // Validate escrow token logic here
        // For now, return a mock user
        return User::first() ?? new User();
    }

    public function validateBearerToken(Request $request): JsonResponse
    {
        $bearerToken = $request->bearerToken();

        if (!$bearerToken) {
            return response()->json([
                'success' => false,
                'message' => 'Bearer token not provided',
                'error_code' => 'MISSING_TOKEN'
            ], 401);
        }

        $tokenRecord = $this->bearerTokenService->validateToken($bearerToken);

        if (!$tokenRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired Bearer token',
                'error_code' => 'INVALID_TOKEN'
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'valid' => true,
                'device_id' => $tokenRecord->device_id
            ]
        ]);
    }
} 