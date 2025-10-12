<?php

namespace App\Http\Controllers\Api;

use App\Services\EscrowTokenService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EscrowTokenController extends BaseApiController
{
    protected EscrowTokenService $escrowTokenService;

    public function __construct(EscrowTokenService $escrowTokenService)
    {
        $this->escrowTokenService = $escrowTokenService;
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
     *             required={"crypto_type"},
     *             @OA\Property(property="crypto_type", type="string", example="xrp", description="Cryptocurrency type (xrp, btc, xmr)"),
     *             @OA\Property(property="amount", type="number", example=10, description="Optional amount")
     *         )
     *     ),
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
            'amount' => 'nullable|numeric|min:0'
        ]);

        try {
            $result = $this->escrowTokenService->createToken(
                $validated['crypto_type'],
                (float)($validated['amount'] ?? 0.0)
            );

            return response()->json($result, 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}

