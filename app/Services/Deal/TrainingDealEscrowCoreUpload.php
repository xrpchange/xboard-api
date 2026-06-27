<?php

declare(strict_types=1);

namespace App\Services\Deal;

use App\Services\EscrowTokenValidationService;
use App\Support\TrainingDealEscrowTokenFormat;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Core consumes encrypted_part once per transfer; re-upload from server-side treasury config before each seller payout.
 */
final class TrainingDealEscrowCoreUpload
{
    public function __construct(
        private readonly EscrowTokenValidationService $escrowTokenValidation,
    ) {}

    public function ensureSellerEncryptedPartUploaded(string $sellerTokenJson): bool
    {
        $normalized = TrainingDealEscrowTokenFormat::normalizeForCore($sellerTokenJson);
        $encrypted = TrainingDealEscrowTokenFormat::encryptedData($normalized);
        $tokenHash = TrainingDealEscrowTokenFormat::tokenHash($normalized);
        $params = TrainingDealEscrowTokenFormat::coreUploadParams($normalized);

        if ($encrypted === null || $tokenHash === null || $params === null) {
            Log::warning('[training-deal] upload encrypted: missing token fields');

            return false;
        }

        [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($normalized);
        $endpoint = rtrim($coreUrl, '/').'/api/escrow-tokens/upload-encrypted-part';

        $req = Http::timeout((int) config('services.core.timeout', 10))->asJson();
        if ($coreKey !== '') {
            $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
        }

        $payload = [
            'crypto_type' => $params['crypto_type'],
            'network' => $params['network'],
            'token_hash' => $tokenHash,
            'encrypted_data' => $encrypted,
        ];

        $resp = $req->post($endpoint, $payload);
        if (! $resp->successful()) {
            Log::warning('[training-deal] upload encrypted failed', [
                'endpoint' => $endpoint,
                'status' => $resp->status(),
                'body' => $resp->body(),
            ]);

            return false;
        }

        Log::info('[training-deal] upload encrypted OK', ['token_hash' => $tokenHash]);

        return true;
    }
}
