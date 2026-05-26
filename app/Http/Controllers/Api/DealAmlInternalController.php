<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DealAmlPersistenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Core-only internal endpoints (X-Core-Key). Not for clients.
 */
class DealAmlInternalController extends Controller
{
    public function __construct(
        private DealAmlPersistenceService $dealAmlPersistence,
    ) {}

    /**
     * GET /api/v1/internal/aml/outstanding-charges?token_hash=...&is_test=0|1
     */
    public function outstandingCharges(Request $request): JsonResponse
    {
        $tokenHash = strtolower(trim((string) $request->query('token_hash', '')));
        if ($tokenHash === '') {
            return response()->json([
                'success' => false,
                'message' => 'token_hash query parameter is required.',
                'error_code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $isTest = filter_var($request->query('is_test', false), FILTER_VALIDATE_BOOL);
        $total = $this->dealAmlPersistence->outstandingChargeAmountForToken($tokenHash, $isTest);

        return response()->json([
            'success' => true,
            'data' => [
                'token_hash' => $tokenHash,
                'is_test' => $isTest,
                'total_amount' => $total,
            ],
        ]);
    }
}
