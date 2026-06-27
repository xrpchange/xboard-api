<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Deal\TrainingDealEscrowCoreUpload;
use App\Services\EscrowTokenValidationService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrainingDealEscrowCoreUploadTest extends TestCase
{
    public function test_uploads_encrypted_part_before_core_transfer(): void
    {
        Http::fake([
            'sandbox.api.core.xdexboard.com/api/escrow-tokens/upload-encrypted-part' => Http::response([
                'status' => 200,
                'data' => ['message' => 'ok'],
            ], 200),
        ]);

        $this->mock(EscrowTokenValidationService::class, function ($mock): void {
            $mock->shouldReceive('getCoreUrlAndKeyForToken')
                ->once()
                ->andReturn(['https://sandbox.api.core.xdexboard.com', '']);
        });

        $token = json_encode([
            'version' => '2.0',
            'wallet_address' => 'GTEST',
            'currency' => 'USDT',
            'network' => 'stellar',
            'token_hash' => 'abc123',
            'encrypted_part' => 'enc-data',
        ], JSON_UNESCAPED_SLASHES);

        $ok = $this->app->make(TrainingDealEscrowCoreUpload::class)->ensureSellerEncryptedPartUploaded($token);

        $this->assertTrue($ok);
        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), 'upload-encrypted-part')
                && ($body['token_hash'] ?? '') === 'abc123'
                && ($body['encrypted_data'] ?? '') === 'enc-data'
                && ($body['crypto_type'] ?? '') === 'usdt'
                && ($body['network'] ?? '') === 'stellar';
        });
    }
}
