<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LotMailboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Lot mailbox (IMAP chat). Delete mailbox when escrow token is removed.
 * See docs/LOT_MAILBOX_AND_IMAP_CHAT.md
 */
class LotMailboxController extends Controller
{
    public function __construct(
        private LotMailboxService $lotMailboxService
    ) {
    }

    /**
     * Try to delete mailbox associated with escrow token. Non-blocking.
     * Expects wallet_address (escrow token public part) from request (via ValidateEscrowToken).
     */
    public function delete(Request $request): JsonResponse
    {
        $walletAddress = $request->header('X-Escrow-Token')
            ?? $request->input('wallet_address')
            ?? $request->input('escrow_token');

        if (!$walletAddress) {
            return response()->json(['success' => false, 'message' => 'wallet_address (escrow token) required'], 422);
        }

        $decoded = json_decode($walletAddress, true);
        if (!is_array($decoded) || empty($decoded['token_hash'])) {
            return response()->json(['success' => false, 'message' => 'Invalid wallet_address: token_hash required'], 422);
        }

        $tokenHash = strtolower(trim($decoded['token_hash']));
        $currency = $decoded['currency'] ?? 'crypto';

        try {
            $deleted = $this->lotMailboxService->deleteByToken($walletAddress, $tokenHash, $currency);
            return response()->json(['success' => true, 'deleted' => $deleted]);
        } catch (\Throwable $e) {
            Log::warning('[lot-mailbox] delete failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
