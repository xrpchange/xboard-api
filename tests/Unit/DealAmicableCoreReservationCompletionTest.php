<?php

namespace Tests\Unit;

use App\Models\ExchangeLot;
use App\Services\Deal\DealAmicableCoreReservationCompletion;
use App\Services\EscrowTokenValidationService;
use App\Support\CryptoToFiatDealSupport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
#[Group('spec-LOT-003')]
class DealAmicableCoreReservationCompletionTest extends TestCase
{
    public function test_crypto_to_fiat_buyer_completion_calls_complete_reservation_with_seller_lot_wallet(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), '/api/complete-reservation')) {
                return Http::response([
                    'status' => 200,
                    'data' => ['deleted' => true, 'service_fee_collected' => true],
                ], 200);
            }

            return Http::response(['message' => 'unexpected url'], 500);
        });

        $lot = new ExchangeLot([
            'aml' => false,
            'wallet_address' => '{"currency":"XMR","wallet_address":"9seller","token_hash":"aabb"}',
            'from_crypto_currency_id' => 1,
            'to_fiat_currency_id' => 2,
        ]);
        $lot->id = 19;
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('toCryptoCurrency', null);
        $lot->setRelation('fromFiatCurrency', null);
        $lot->setRelation('cryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);

        $validation = $this->createMock(EscrowTokenValidationService::class);
        $validation->method('getCoreUrlAndKeyForToken')
            ->willReturn(['https://sandbox.api.core.example.test', 'test-key']);

        $service = new DealAmicableCoreReservationCompletion($validation);
        $request = Request::create('/send-message', 'POST', [], [], [], [
            'HTTP_X-Escrow-Token' => '{"currency":"XMR","wallet_address":"9buyer","token_hash":"ccdd"}',
        ]);

        $result = $service->completeBeforeAmicableChat(
            $lot,
            $request,
            ['t' => 'deal_completed_buyer', 'lot_id' => '19'],
            'deal_completed_buyer',
        );

        $this->assertTrue(CryptoToFiatDealSupport::buyerCryptoFirstLegCryptoToFiat($lot));
        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('skipped', $result);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/complete-reservation')
            && ! str_contains($request->url(), 'complete-reservation-by-buyer'));
    }

    public function test_fiat_to_crypto_buyer_completion_calls_complete_reservation_with_seller_lot_wallet(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), '/api/complete-reservation')) {
                return Http::response([
                    'status' => 200,
                    'data' => ['deleted' => true, 'service_fee_collected' => true],
                ], 200);
            }

            return Http::response(['message' => 'unexpected url'], 500);
        });

        $lot = new ExchangeLot([
            'aml' => false,
            'wallet_address' => '{"currency":"XMR","wallet_address":"9seller","token_hash":"aabb"}',
            'from_fiat_currency_id' => 1,
            'to_crypto_currency_id' => 2,
            'from_currency' => 'USD',
            'to_currency' => 'xmr',
        ]);
        $lot->id = 21;
        $lot->setRelation('fromFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('fromCryptoCurrency', null);
        $lot->setRelation('toFiatCurrency', null);
        $lot->setRelation('cryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);

        $validation = $this->createMock(EscrowTokenValidationService::class);
        $validation->method('getCoreUrlAndKeyForToken')
            ->willReturn(['https://sandbox.api.core.example.test', 'test-key']);

        $service = new DealAmicableCoreReservationCompletion($validation);
        $request = Request::create('/send-message', 'POST', [], [], [], [
            'HTTP_X-Escrow-Token' => '{"currency":"XMR","wallet_address":"9buyer","token_hash":"ccdd"}',
        ]);

        $result = $service->completeBeforeAmicableChat(
            $lot,
            $request,
            ['t' => 'deal_completed_buyer', 'lot_id' => '21'],
            'deal_completed_buyer',
        );

        $this->assertTrue(CryptoToFiatDealSupport::buyerFiatFirstLegFiatToCrypto($lot));
        $this->assertTrue($result['ok']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/complete-reservation')
            && ! str_contains($request->url(), 'complete-reservation-by-buyer'));
    }

    public function test_buyer_completion_fails_when_core_returns_error(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            return Http::response([
                'message' => 'Service fee transfer failed.',
                'error_code' => 'TRANSFER_FAILED',
            ], 500);
        });

        $lot = new ExchangeLot([
            'aml' => false,
            'wallet_address' => '{"currency":"XMR","wallet_address":"9seller","token_hash":"aabb"}',
            'from_crypto_currency_id' => 1,
            'to_fiat_currency_id' => 2,
        ]);
        $lot->id = 21;
        $lot->setRelation('fromCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('toFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('cryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);

        $validation = $this->createMock(EscrowTokenValidationService::class);
        $validation->method('getCoreUrlAndKeyForToken')
            ->willReturn(['https://sandbox.api.core.example.test', '']);

        $service = new DealAmicableCoreReservationCompletion($validation);
        $request = Request::create('/send-message', 'POST');

        $result = $service->completeBeforeAmicableChat(
            $lot,
            $request,
            ['t' => 'deal_completed_buyer', 'lot_id' => '21'],
            'deal_completed_buyer',
        );

        $this->assertArrayNotHasKey('skipped', $result);
        $this->assertFalse($result['ok']);
        $this->assertSame('TRANSFER_FAILED', $result['error_code']);
        Http::assertSentCount(1);
    }

    public function test_non_completion_tag_is_skipped(): void
    {
        Http::fake();

        $lot = new ExchangeLot(['id' => 1]);
        $validation = $this->createMock(EscrowTokenValidationService::class);
        $service = new DealAmicableCoreReservationCompletion($validation);

        $result = $service->completeBeforeAmicableChat(
            $lot,
            Request::create('/'),
            ['t' => 'receipt_confirmation', 'lot_id' => '1'],
            'receipt_confirmation',
        );

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['skipped'] ?? false);
        Http::assertNothingSent();
    }

    public function test_buyer_completion_succeeds_after_transport_timeout_when_core_already_completed(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response([
                'message' => 'Matching reservation service fee state not found.',
                'error_code' => 'CRYPTO_ERROR',
            ], 404);
        });

        $lot = new ExchangeLot([
            'aml' => false,
            'wallet_address' => '{"currency":"XMR","wallet_address":"9seller","token_hash":"aabb"}',
            'from_fiat_currency_id' => 1,
            'to_crypto_currency_id' => 2,
            'from_currency' => 'USD',
            'to_currency' => 'xmr',
        ]);
        $lot->id = 21;
        $lot->setRelation('fromFiatCurrency', (object) ['code' => 'USD']);
        $lot->setRelation('toCryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);
        $lot->setRelation('fromCryptoCurrency', null);
        $lot->setRelation('toFiatCurrency', null);
        $lot->setRelation('cryptoCurrency', (object) ['code' => 'xmr', 'is_native' => true, 'network' => 'native']);

        $validation = $this->createMock(EscrowTokenValidationService::class);
        $validation->method('getCoreUrlAndKeyForToken')
            ->willReturn(['https://sandbox.api.core.example.test', 'test-key']);

        $service = new DealAmicableCoreReservationCompletion($validation);
        $request = Request::create('/send-message', 'POST', [], [], [], [
            'HTTP_X-Escrow-Token' => '{"currency":"XMR","wallet_address":"9buyer","token_hash":"ccdd"}',
        ]);

        $result = $service->completeBeforeAmicableChat(
            $lot,
            $request,
            ['t' => 'deal_completed_buyer', 'lot_id' => '21'],
            'deal_completed_buyer',
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $calls);
    }
}
