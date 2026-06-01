<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExchangeLotResource;
use App\Mail\HighDeliveryPriority;
use App\Models\Bank;
use App\Models\Country;
use App\Models\CryptoCurrency;
use App\Models\DealChatMessage;
use App\Models\DealDispute;
use App\Models\ExchangeLot;
use App\Models\FiatCurrency;
use App\Models\Node;
use App\Models\Transaction;
use App\Services\Arbiter\ArbiterService;
use App\Services\Deal\DealWebPushNotifier;
use App\Services\DealAmlAdminAlertService;
use App\Services\DealAmlPersistenceService;
use App\Services\EscrowTokenValidationService;
use App\Services\ReceiptAttachmentStorage;
use App\Services\SandboxVirtualBankService;
use App\Support\ArbiterPromptContextAssembler;
use App\Support\ArbiterReceiptPdfResolver;
use App\Support\ClientWebOrigin;
use App\Support\DealBuyerEscrowTokenHash;
use App\Support\DealChatTagSequenceValidator;
use App\Support\DealSaleTransactionCompletion;
use App\Support\DealSellerOpenLinks;
use App\Support\DealTerminalLotClosure;
use App\Support\PublicHttpsUrl;
use App\Support\SandboxTestReceiptPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * @OA\Tag(
 *     name="Exchange Lots",
 *     description="Endpoints for managing exchange lots"
 * )
 */
class ExchangeLotController extends Controller
{
    public function __construct(
        private EscrowTokenValidationService $escrowTokenValidation,
        private \App\Services\LotMailboxService $lotMailboxService,
        private ArbiterService $arbiterService,
        private DealAmlPersistenceService $dealAmlPersistence,
        private DealAmlAdminAlertService $dealAmlAdminAlert,
        private DealWebPushNotifier $dealWebPushNotifier,
    ) {}

    /**
     * IPv4/IPv6 from trusted proxy-aware request (TrustedProxies / X-Forwarded-For). Stored for seller anti-abuse only.
     */
    private static function normalizeBuyerRequestIp(Request $request): ?string
    {
        $raw = trim((string) ($request->ip() ?? ''));
        if ($raw === '' || $raw === '0.0.0.0') {
            return null;
        }
        $sanitized = preg_replace('/[^0-9a-fA-F:.\[\]%]/', '', $raw) ?? '';
        $sanitized = trim((string) $sanitized);
        if ($sanitized === '') {
            return null;
        }
        $len = strlen($sanitized);

        return $len > 45 ? substr($sanitized, 0, 45) : $sanitized;
    }

    /**
     * Temporary buyer connection info on the lot until send_payment_details copies it onto the sale transaction.
     */
    private static function syncBuyerIpOnExchangeLot(int $lotId, Request $request): void
    {
        $share = $request->boolean('share_buyer_ip_with_seller');
        $ip = $share ? self::normalizeBuyerRequestIp($request) : null;
        DB::table('exchange_lots')->where('id', $lotId)->update([
            'buyer_seen_ip' => $ip,
            'buyer_ip_share_with_seller' => $share,
        ]);
    }

    private static function sellerEscrowCryptoCurrency(ExchangeLot $lot): ?CryptoCurrency
    {
        $lot->loadMissing(['fromCryptoCurrency', 'toCryptoCurrency', 'cryptoCurrency']);
        if ($lot->fromCryptoCurrency) {
            return $lot->fromCryptoCurrency;
        }
        if ($lot->toCryptoCurrency) {
            return $lot->toCryptoCurrency;
        }

        return $lot->cryptoCurrency;
    }

    private static function coreCryptoTypeFromCurrencyRow(CryptoCurrency $row): string
    {
        $code = strtolower(trim((string) $row->code));
        if ($code === '') {
            throw new \InvalidArgumentException('Linked crypto currency has empty code.');
        }
        if (preg_match('/_(stellar|polygon|tron)$/', $code)) {
            return $code;
        }
        $network = strtolower(trim((string) ($row->network ?? '')));
        if ($network !== '' && $network !== 'native' && ! str_contains($code, '_')) {
            return $code.'_'.$network;
        }

        return $code;
    }

    private static function coreCryptoTypeForSellerEscrow(ExchangeLot $lot): string
    {
        $row = self::sellerEscrowCryptoCurrency($lot);
        if ($row === null) {
            throw new \InvalidArgumentException('Lot has no crypto currency for escrow (from_crypto, to_crypto, or crypto_currency_id).');
        }

        return self::coreCryptoTypeFromCurrencyRow($row);
    }

    private static function buyerEscrowCryptoCurrency(ExchangeLot $lot): ?CryptoCurrency
    {
        $lot->loadMissing(['fromCryptoCurrency', 'toCryptoCurrency', 'cryptoCurrency']);
        if ($lot->toCryptoCurrency) {
            return $lot->toCryptoCurrency;
        }
        if ($lot->fromCryptoCurrency) {
            return $lot->fromCryptoCurrency;
        }

        return $lot->cryptoCurrency;
    }

    private static function coreCryptoTypeForBuyerEscrow(ExchangeLot $lot): string
    {
        $row = self::buyerEscrowCryptoCurrency($lot);
        if ($row === null) {
            throw new \InvalidArgumentException('Lot has no crypto currency for buyer escrow (to_crypto, from_crypto, or crypto_currency_id).');
        }

        return self::coreCryptoTypeFromCurrencyRow($row);
    }

    private static function sellerMailboxCryptoCode(ExchangeLot $lot): string
    {
        return self::sellerEscrowCryptoCurrency($lot)?->code ?? ($lot->cryptoCurrency?->code ?? 'crypto');
    }

    /** Tron T…, Stellar G…, Polygon 0x… — chain where funds actually live. */
    private static function inferStablecoinNetworkFromBlockchainAddress(string $address): ?string
    {
        $w = trim($address);
        if ($w === '') {
            return null;
        }
        if (preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $w)) {
            return 'tron';
        }
        if (preg_match('/^G[A-Z2-7]{55}$/', $w)) {
            return 'stellar';
        }
        if (preg_match('/^0x[a-fA-F0-9]{40}$/', $w)) {
            return 'polygon';
        }

        return null;
    }

    private static function coreNetworkFromCryptoType(string $cryptoType): ?string
    {
        if (preg_match('/^(usdt|usdc|dai)_(stellar|polygon|tron)$/', strtolower(trim($cryptoType)), $m)) {
            return $m[2];
        }

        return null;
    }

    /**
     * Stablecoin payout destinations must match the deal asset network (Stellar G…, Tron T…, Polygon 0x…).
     *
     * @return array{message: string, error_code: string}|null
     */
    private static function validateStablecoinPayoutDestinationAddress(string $address, string $cryptoType): ?array
    {
        $payoutNetwork = self::coreNetworkFromCryptoType($cryptoType);
        if ($payoutNetwork === null) {
            return null;
        }
        $destNet = self::inferStablecoinNetworkFromBlockchainAddress($address);
        $hint = match ($payoutNetwork) {
            'stellar' => 'Stellar account (starts with G, 56 characters)',
            'tron' => 'Tron address (starts with T)',
            'polygon' => 'Polygon address (0x + 40 hex)',
            default => $payoutNetwork,
        };
        if ($destNet === null) {
            return [
                'message' => 'Invalid payout address for this deal. Expected '.$hint.'.',
                'error_code' => 'crypto_address_invalid',
            ];
        }
        if ($destNet !== $payoutNetwork) {
            return [
                'message' => 'Payout address network ('.$destNet.') does not match deal payment asset ('.$payoutNetwork.'). Expected '.$hint.'.',
                'error_code' => 'crypto_address_network_mismatch',
            ];
        }

        return null;
    }

    /**
     * Buyer payout must use the deal's payment asset (lot), not a different USDT network from the token JSON alone.
     */
    private static function resolveBuyerDealPayoutCryptoType(ExchangeLot $lot, string $buyerEscrowToken): string
    {
        $lot->loadMissing(['fromCryptoCurrency', 'toCryptoCurrency', 'cryptoCurrency']);
        $lotType = self::coreCryptoTypeForBuyerEscrow($lot);
        $tokenType = self::coreCryptoTypeFromEscrowTokenJson($buyerEscrowToken);
        if ($tokenType === null) {
            return $lotType;
        }
        if ($tokenType === $lotType) {
            return $lotType;
        }
        if (preg_match('/^(usdt|usdc|dai)_(stellar|polygon|tron)$/', $tokenType)
            && preg_match('/^(usdt|usdc|dai)_(stellar|polygon|tron)$/', $lotType)
        ) {
            throw new \InvalidArgumentException(
                'Buyer escrow token network does not match this deal. Deal requires '.$lotType.'; token is '.$tokenType.'.'
            );
        }

        return $tokenType;
    }

    /** Core validateEscrowToken matches token currency to crypto_type; prefer values derived from the token JSON. */
    private static function coreCryptoTypeFromEscrowTokenJson(string $escrowTokenJson): ?string
    {
        $decoded = json_decode($escrowTokenJson, true);
        if (! is_array($decoded) || ! isset($decoded['currency'])) {
            return null;
        }
        $raw = trim((string) $decoded['currency']);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(USDT|USDC|DAI)_(STELLAR|POLYGON|TRON)$/i', $raw, $m)) {
            return strtolower($m[1].'_'.$m[2]);
        }
        $currencyUpper = strtoupper($raw);
        $walletAddress = isset($decoded['wallet_address']) && is_string($decoded['wallet_address'])
            ? trim($decoded['wallet_address'])
            : '';
        $inferredNet = self::inferStablecoinNetworkFromBlockchainAddress($walletAddress);
        $network = $inferredNet ?? (isset($decoded['network']) ? strtolower(trim((string) $decoded['network'])) : '');
        if ($network !== '' && in_array($network, ['stellar', 'tron', 'polygon'], true)) {
            foreach (['USDT' => 'usdt', 'USDC' => 'usdc', 'DAI' => 'dai'] as $sym => $prefix) {
                if ($currencyUpper === $sym) {
                    return $prefix.'_'.$network;
                }
            }
        }

        return match ($currencyUpper) {
            'XRP' => 'xrp',
            'BTC' => 'btc',
            'XMR' => 'xmr',
            'MATIC', 'POLYGON' => 'polygon',
            default => null,
        };
    }

    /**
     * Best-effort Core release after amicable buyer completion (deal_completed_buyer).
     * Same contract as buyer_exited; failures are logged only.
     */
    private function tryDeleteCoreReservationAfterBuyerAmicableCompletion(ExchangeLot $lot, Request $request, string $reason): void
    {
        $buyerToken = $request->input('escrow_token') ?? $request->header('X-Escrow-Token');
        if (empty($buyerToken) || ! is_string($buyerToken)) {
            Log::info('[exchange-lots] Core delete-reservation-by-buyer skipped (no escrow token)', [
                'lot_id' => $lot->id,
                'reason' => $reason,
            ]);

            return;
        }
        $lot->loadMissing(['cryptoCurrency', 'fromCryptoCurrency', 'toCryptoCurrency']);
        try {
            [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($buyerToken);
            try {
                $cryptoType = self::coreCryptoTypeFromEscrowTokenJson($buyerToken)
                    ?? self::coreCryptoTypeForBuyerEscrow($lot);
            } catch (\InvalidArgumentException) {
                $cryptoType = strtolower((string) ($lot->cryptoCurrency?->code ?? 'xmr'));
            }
            $timeout = (int) config('services.core.timeout', 10);
            $req = Http::timeout($timeout);
            if ($coreKey !== '') {
                $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
            }
            $coreEndpoint = rtrim($coreUrl, '/').'/api/complete-reservation-by-buyer';
            Log::info('[exchange-lots] Core complete-reservation-by-buyer start (amicable buyer completion)', [
                'lot_id' => $lot->id,
                'reason' => $reason,
                'core_endpoint' => $coreEndpoint,
                'timeout_seconds' => $timeout,
                'crypto_type' => $cryptoType,
            ]);
            $startedAt = microtime(true);
            $resp = $req->post($coreEndpoint, [
                'exchange_lot_id' => (string) $lot->id,
                'escrow_token' => $buyerToken,
                'crypto_type' => $cryptoType,
                'aml_required' => (bool) $lot->aml,
            ]);
            $responseBody = $resp->json();
            $responseBodyPreview = is_array($responseBody)
                ? json_encode($responseBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $resp->body();
            if (is_string($responseBodyPreview) && strlen($responseBodyPreview) > 500) {
                $responseBodyPreview = substr($responseBodyPreview, 0, 500).'...';
            }
            if ($resp->successful()) {
                Log::info('[exchange-lots] Core complete-reservation-by-buyer OK (amicable buyer completion)', [
                    'lot_id' => $lot->id,
                    'reason' => $reason,
                    'took_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'core_body_preview' => $responseBodyPreview,
                ]);
            } else {
                Log::warning('[exchange-lots] Core complete-reservation-by-buyer failed (amicable buyer completion)', [
                    'lot_id' => $lot->id,
                    'reason' => $reason,
                    'took_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'status' => $resp->status(),
                    'body' => $responseBodyPreview,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] Core complete-reservation-by-buyer exception (amicable buyer completion)', [
                'lot_id' => $lot->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Best-effort Core release after seller posts deal_completed_seller_timeout.
     * Same contract as seller_exited; failures are logged only.
     */
    private function tryDeleteCoreReservationAfterSellerAmicableCompletion(ExchangeLot $lot, Request $request, string $reason): void
    {
        $sellerEscrowToken = $request->input('escrow_token') ?? $request->header('X-Escrow-Token');
        if (empty($sellerEscrowToken) || ! is_string($sellerEscrowToken)) {
            Log::info('[exchange-lots] Core delete-reservation skipped (no escrow token)', [
                'lot_id' => $lot->id,
                'reason' => $reason,
            ]);

            return;
        }
        $this->postCoreCompleteReservationSeller($lot, $sellerEscrowToken, $reason);
    }

    /**
     * Best-effort Core seller-side reservation release (complete-reservation).
     */
    private function postCoreCompleteReservationSeller(ExchangeLot $lot, string $sellerEscrowToken, string $reason): void
    {
        $lot->loadMissing(['cryptoCurrency', 'fromCryptoCurrency', 'toCryptoCurrency']);
        try {
            [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($sellerEscrowToken);
            try {
                $cryptoType = self::coreCryptoTypeFromEscrowTokenJson($sellerEscrowToken)
                    ?? self::coreCryptoTypeForSellerEscrow($lot);
            } catch (\InvalidArgumentException) {
                $cryptoType = strtolower((string) ($lot->cryptoCurrency?->code ?? 'xmr'));
            }
            $timeout = (int) config('services.core.timeout', 10);
            $req = Http::timeout($timeout);
            if ($coreKey !== '') {
                $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
            }
            $coreEndpoint = rtrim($coreUrl, '/').'/api/complete-reservation';
            Log::info('[exchange-lots] Core complete-reservation start (amicable seller timeout completion)', [
                'lot_id' => $lot->id,
                'reason' => $reason,
                'core_endpoint' => $coreEndpoint,
                'timeout_seconds' => $timeout,
                'crypto_type' => $cryptoType,
            ]);
            $startedAt = microtime(true);
            $resp = $req->post($coreEndpoint, [
                'exchange_lot_id' => (string) $lot->id,
                'escrow_token' => $sellerEscrowToken,
                'crypto_type' => $cryptoType,
                'aml_required' => (bool) $lot->aml,
            ]);
            $responseBody = $resp->json();
            $responseBodyPreview = is_array($responseBody)
                ? json_encode($responseBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $resp->body();
            if (is_string($responseBodyPreview) && strlen($responseBodyPreview) > 500) {
                $responseBodyPreview = substr($responseBodyPreview, 0, 500).'...';
            }
            if ($resp->successful()) {
                Log::info('[exchange-lots] Core complete-reservation OK (amicable seller timeout completion)', [
                    'lot_id' => $lot->id,
                    'reason' => $reason,
                    'took_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'core_body_preview' => $responseBodyPreview,
                ]);
            } else {
                Log::warning('[exchange-lots] Core complete-reservation failed (amicable seller timeout completion)', [
                    'lot_id' => $lot->id,
                    'reason' => $reason,
                    'took_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'status' => $resp->status(),
                    'body' => $responseBodyPreview,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] Core complete-reservation exception (amicable seller timeout completion)', [
                'lot_id' => $lot->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Return first active buyer deal for the request token (if exists).
     * Used by clients to restore in-progress buyer deal from server state
     * when local state is missing (e.g. app on another device).
     */
    public function buyerActiveDeal(Request $request): JsonResponse
    {
        $tokenData = $request->input('escrow_token_data', []);
        $requestTokenHash = is_array($tokenData) ? trim((string) ($tokenData['token_hash'] ?? '')) : '';
        $queryHashesRaw = $request->query('token_hashes', '');
        $queryHashes = is_string($queryHashesRaw)
            ? array_filter(array_map(static fn ($h) => strtolower(trim((string) $h)), explode(',', $queryHashesRaw)))
            : [];
        $hashes = array_values(array_unique(array_filter(array_merge(
            $queryHashes,
            $requestTokenHash !== '' ? [strtolower($requestTokenHash)] : []
        ))));
        Log::info('[exchange-lots] buyerActiveDeal: request', [
            'hashes_count' => count($hashes),
            'request_token_hash_prefix' => $requestTokenHash !== '' ? substr(strtolower($requestTokenHash), 0, 8) : null,
            'query_hash_prefixes' => array_slice(array_map(static fn (string $h) => substr($h, 0, 8), $queryHashes), 0, 10),
        ]);
        if (count($hashes) === 0) {
            Log::warning('[exchange-lots] buyerActiveDeal: no hashes provided');

            return response()->json([
                'success' => false,
                'message' => 'No token hashes provided.',
                'error_code' => 'invalid_token_data',
            ], 401);
        }

        $lotQuery = ExchangeLot::query()
            ->whereNotNull('buyer_lot_email')
            ->where('buyer_lot_email', '<>', '')
            ->where(function ($q) use ($hashes) {
                $q->whereNotNull('buyer_escrow_token_hash')
                    ->whereIn(DB::raw('LOWER(TRIM(buyer_escrow_token_hash))'), $hashes);
            });
        $lot = $lotQuery->orderBy('id')->first();

        if ($lot === null) {
            Log::info('[exchange-lots] buyerActiveDeal: no active lot found', [
                'hashes_count' => count($hashes),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No active buyer deal found.',
                'error_code' => 'no_active_deal',
            ], 404);
        }
        Log::info('[exchange-lots] buyerActiveDeal: found', [
            'lot_id' => $lot->id,
            'matched_hash_prefix' => substr(strtolower(trim((string) $lot->buyer_escrow_token_hash)), 0, 8),
            'buyer_lot_email' => $lot->buyer_lot_email,
        ]);

        $lot->load(['cryptoCurrency', 'node', 'bank', 'banks', 'fromFiatCurrency', 'fromCryptoCurrency', 'toFiatCurrency', 'toCryptoCurrency']);

        return response()->json([
            'success' => true,
            'data' => [
                'lot' => new \App\Http\Resources\ExchangeLotResource($lot),
                'matched_token_hash' => strtolower(trim((string) $lot->buyer_escrow_token_hash)),
            ],
        ]);
    }

    /**
     * Return all active buyer deals matching provided token hashes.
     * Used for resilient restore/manual resume list on client.
     */
    public function buyerActiveDeals(Request $request): JsonResponse
    {
        $tokenData = $request->input('escrow_token_data', []);
        $requestTokenHash = is_array($tokenData) ? trim((string) ($tokenData['token_hash'] ?? '')) : '';
        $queryHashesRaw = $request->query('token_hashes', '');
        $queryHashes = is_string($queryHashesRaw)
            ? array_filter(array_map(static fn ($h) => strtolower(trim((string) $h)), explode(',', $queryHashesRaw)))
            : [];
        $hashes = array_values(array_unique(array_filter(array_merge(
            $queryHashes,
            $requestTokenHash !== '' ? [strtolower($requestTokenHash)] : []
        ))));
        if (count($hashes) === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No token hashes provided.',
                'error_code' => 'invalid_token_data',
            ], 401);
        }

        Log::info('[exchange-lots] buyerActiveDeals: request', [
            'hashes_count' => count($hashes),
            'query_hash_prefixes' => array_slice(array_map(static fn (string $h) => substr($h, 0, 8), $queryHashes), 0, 10),
        ]);

        $lots = ExchangeLot::query()
            ->whereNotNull('buyer_lot_email')
            ->where('buyer_lot_email', '<>', '')
            ->where(function ($q) use ($hashes) {
                $q->whereNotNull('buyer_escrow_token_hash')
                    ->whereIn(DB::raw('LOWER(TRIM(buyer_escrow_token_hash))'), $hashes);
            })
            ->orderBy('id')
            ->limit(20)
            ->get();
        Log::info('[exchange-lots] buyerActiveDeals: found', ['count' => $lots->count()]);

        if ($lots->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'items' => [],
                ],
            ]);
        }

        $lots->load(['cryptoCurrency', 'node', 'bank', 'banks', 'fromFiatCurrency', 'fromCryptoCurrency', 'toFiatCurrency', 'toCryptoCurrency']);

        $items = $lots->map(function (ExchangeLot $lot): array {
            return [
                'lot' => new \App\Http\Resources\ExchangeLotResource($lot),
                'matched_token_hash' => strtolower(trim((string) $lot->buyer_escrow_token_hash)),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items,
            ],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/exchange-lots",
     *     summary="List exchange lots",
     *     tags={"Exchange Lots"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful response"
     *     ),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $walletAddresses = $request->input('wallet_address') ?? $request->input('wallet_address[]');
        $walletCount = is_array($walletAddresses) ? count($walletAddresses) : ($walletAddresses ? 1 : 0);
        Log::info('[exchange-lots] REQUEST', [
            'host' => $request->getHost(),
            'app_env' => config('app.env'),
            'db_database' => config('database.connections.'.config('database.default').'.database'),
            'from_currency' => $request->get('from_currency'),
            'to_currency' => $request->get('to_currency'),
            'country_code' => $request->get('country_code'),
            'country_id' => $request->get('country_id'),
            'bank_swift' => $request->get('bank_swift'),
            'bank_id' => $request->get('bank_id'),
            'accept_from_bank_swift' => $request->get('accept_from_bank_swift'),
            'accept_from_bank_id' => $request->get('accept_from_bank_id'),
            'is_test' => $request->get('is_test'),
            'test_group' => $request->get('test_group'),
            'main_group' => $request->get('main_group'),
            'aml_max' => $request->get('aml_max'),
            'include_inactive' => $request->get('include_inactive'),
            'wallet_address_count' => $walletCount,
        ]);

        $includeInactive = filter_var($request->get('include_inactive', false), FILTER_VALIDATE_BOOLEAN);
        $query = ($includeInactive ? ExchangeLot::query() : ExchangeLot::active())
            ->with(['cryptoCurrency', 'node', 'bank', 'banks', 'fromFiatCurrency', 'fromCryptoCurrency', 'toFiatCurrency', 'toCryptoCurrency']);

        if ($request->has('is_test')) {
            if ($request->is_test === 'true') {
                $testGroupHash = $request->filled('test_group') ? hash('sha256', trim($request->test_group)) : null;
                $query->test($testGroupHash);
            } else {
                $mainGroupHash = $request->filled('main_group') ? hash('sha256', trim($request->main_group)) : null;
                $query->main($mainGroupHash);
            }
        }

        if ($request->filled('from_currency')) {
            $code = strtoupper($request->from_currency);
            $query->where(function ($q) use ($code) {
                $q->whereHas('fromFiatCurrency', fn ($q2) => $q2->where('code', $code))
                    ->orWhereHas('fromCryptoCurrency', fn ($q2) => $q2->whereRaw('LOWER(code) = ?', [strtolower($code)]));
            });
        }
        if ($request->filled('to_currency')) {
            $code = strtoupper($request->to_currency);
            $query->where(function ($q) use ($code) {
                $q->whereHas('toFiatCurrency', fn ($q2) => $q2->where('code', $code))
                    ->orWhereHas('toCryptoCurrency', fn ($q2) => $q2->whereRaw('LOWER(code) = ?', [strtolower($code)]));
            });
        }
        if ($request->has('node_id')) {
            $query->where('node_id', $request->node_id);
        }
        // Country filter: prefer country_code (cross-node) over country_id
        $countryId = null;
        if ($request->filled('country_code')) {
            $country = Country::where('code', strtoupper(trim($request->country_code)))->first();
            $countryId = $country?->id;
        }
        if ($countryId === null && $request->filled('country_id')) {
            $countryId = (int) $request->country_id;
        }
        if ($countryId !== null) {
            $query->where(function ($q) use ($countryId) {
                $q->whereHas('bank', fn ($b) => $b->where('country_id', $countryId))
                    ->orWhereNull('bank_id');
            });
        }
        if ($request->has('for_individuals') && $request->for_individuals === 'true') {
            $query->forIndividuals();
        }
        if ($request->has('for_legal_entities') && $request->for_legal_entities === 'true') {
            $query->forLegalEntities();
        }
        // bank = recipient bank (where seller receives money). Prefer bank_swift (cross-node) over bank_id
        $recipientBankId = null;
        if ($request->filled('bank_swift')) {
            $swift = trim((string) $request->bank_swift);
            $bank = Bank::whereRaw('UPPER(TRIM(swift_code)) = ?', [strtoupper($swift)])->first();
            if ($bank !== null) {
                $recipientBankId = (int) $bank->id;
            } elseif (SandboxVirtualBankService::matchesVirtualSwift($swift)) {
                $recipientBankId = SandboxVirtualBankService::virtualBankId();
            }
        }
        if ($recipientBankId === null && $request->filled('bank_id')) {
            $recipientBankId = (int) $request->bank_id;
        }
        if ($recipientBankId !== null) {
            if (SandboxVirtualBankService::isVirtualBankId($recipientBankId)) {
                $query->where('virtual_sandbox_bank_code', SandboxVirtualBankService::bankCode());
            } else {
                $query->where('bank_id', $recipientBankId);
            }
        }
        // accept_from_bank = lots that accept payment from this bank. Prefer accept_from_bank_swift over accept_from_bank_id
        $acceptFromBankId = null;
        if ($request->filled('accept_from_bank_swift')) {
            $bank = Bank::where('swift_code', trim($request->accept_from_bank_swift))->first();
            $acceptFromBankId = $bank?->id;
        }
        if ($acceptFromBankId === null && $request->filled('accept_from_bank_id')) {
            $acceptFromBankId = (int) $request->accept_from_bank_id;
        }
        if ($acceptFromBankId !== null) {
            $query->where(function ($q) use ($acceptFromBankId) {
                $q->whereHas('banks', fn ($b) => $b->where('banks.id', $acceptFromBankId))
                    ->orWhereDoesntHave('banks');
            });
        }
        // AML: when aml_max is set, show only lots where AML wallet check is enabled.
        // Numeric threshold filtering is done on the client using per-node Core /info.
        if ($request->filled('aml_max')) {
            $query->where('aml', true);
        }
        $walletAddresses = $request->input('wallet_address') ?? $request->input('wallet_address[]');
        $hashes = [];
        if (! empty($walletAddresses)) {
            $addresses = is_array($walletAddresses) ? $walletAddresses : [$walletAddresses];
            $addresses = array_filter(array_map('trim', $addresses));
            foreach ($addresses as $a) {
                $decoded = json_decode($a, true);
                if (is_array($decoded) && ! empty($decoded['token_hash'])) {
                    $hashes[] = strtolower(trim($decoded['token_hash']));
                } else {
                    Log::warning('[exchange-lots] Failed to parse wallet_address', [
                        'raw_preview' => is_string($a) ? substr($a, 0, 200) : gettype($a),
                        'json_error' => json_last_error_msg(),
                    ]);
                }
            }
            $hashes = array_values(array_unique(array_filter($hashes)));
            Log::info('[exchange-lots] Extracted hashes for query', ['hashes' => $hashes, 'count' => count($hashes)]);
            if (! empty($hashes)) {
                $query->whereIn('wallet_hash', $hashes);
            }
        }

        // Public search: exclude lots that have a buyer in deal (buyer_lot_email set)
        if (empty($hashes)) {
            $query->where(function ($q) {
                $q->whereNull('buyer_lot_email')->orWhere('buyer_lot_email', '');
            });
            // Show only lots that still have remaining volume and at least one tier that fits remaining "from" volume at rate.
            $query->whereRaw('(sell_amount - COALESCE(sold_crypto_amount, 0)) > 0');
            $query->whereRaw(
                '(buy_amount_min / NULLIF(CAST(`rate` AS DECIMAL(24,14)), 0)) <= (sell_amount - COALESCE(sold_crypto_amount, 0))'
            );
        }

        // Sorting
        $query->orderBy('created_at', 'desc');

        Log::info('[exchange-lots] FILTERS', [
            'from_currency' => $request->filled('from_currency'),
            'to_currency' => $request->filled('to_currency'),
            'country_code' => $request->filled('country_code'),
            'country_id' => $request->filled('country_id'),
            'bank_swift' => $request->filled('bank_swift'),
            'bank_id' => $request->filled('bank_id'),
            'accept_from_bank_swift' => $request->filled('accept_from_bank_swift'),
            'accept_from_bank_id' => $request->filled('accept_from_bank_id'),
            'aml_max' => $request->filled('aml_max'),
            'is_test' => $request->has('is_test'),
            'test_group' => $request->filled('test_group'),
            'main_group' => $request->filled('main_group'),
            'wallet_hashes_count' => count($hashes),
        ]);

        $lots = $query->paginate($request->get('per_page', 20));
        $items = $lots->items();
        Log::info('[exchange-lots] RESPONSE', [
            'returned_count' => count($items),
            'total' => $lots->total(),
        ]);
        $testGroupHashForTotal = $request->filled('test_group') ? hash('sha256', trim($request->test_group)) : null;
        $mainGroupHashForTotal = $request->filled('main_group') ? hash('sha256', trim($request->main_group)) : null;
        $totalAllTest = ExchangeLot::when($request->has('is_test') && $request->is_test === 'true', fn ($q) => $q->test($testGroupHashForTotal))
            ->when($request->has('is_test') && $request->is_test !== 'true', fn ($q) => $q->main($mainGroupHashForTotal))
            ->count();
        $count = count($items);

        $responseLog = ['wallet_hash_count' => count($hashes), 'lots_count' => $count];
        if (! empty($hashes)) {
            $responseLog['hash_prefixes'] = array_map(fn ($h) => substr($h, 0, 12).'...', array_slice($hashes, 0, 5));
        }
        $responseLog['returned_ids'] = array_map(fn ($l) => $l->id, $items);
        $responseLog['returned_wallet_hashes'] = array_map(fn ($l) => substr($l->wallet_hash ?? '', 0, 12).'...', $items);
        $responseLog['total_all_test_in_db'] = $totalAllTest;
        // When filter returns 0 but DB has lots — log their wallet_hashes for debugging
        if ($count === 0 && $totalAllTest > 0 && ! empty($hashes)) {
            $allTestLots = ExchangeLot::when($request->has('is_test') && $request->is_test === 'true', fn ($q) => $q->test($testGroupHashForTotal))
                ->when($request->has('is_test') && $request->is_test !== 'true', fn ($q) => $q->main($mainGroupHashForTotal))
                ->get(['id', 'wallet_hash']);
            $responseLog['db_test_wallet_hashes'] = $allTestLots->map(fn ($l) => substr($l->wallet_hash ?? '', 0, 16).'...')->all();
        }
        Log::info('[exchange-lots] RESPONSE', $responseLog);

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\ExchangeLotResource::collection($lots->items()),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/exchange-lots/{id}",
     *     summary="Get exchange lot by ID",
     *     tags={"Exchange Lots"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Successful response"),
     *     @OA\Response(response=401, description="Unauthorized"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function show(ExchangeLot $lot): JsonResponse
    {
        $lot->load(['cryptoCurrency', 'node', 'bank', 'fromFiatCurrency', 'fromCryptoCurrency', 'toFiatCurrency', 'toCryptoCurrency']);

        return response()->json([
            'success' => true,
            'data' => (new ExchangeLotResource($lot))->toArray(request()),
        ]);
    }

    /**
     * GET /exchange-lots/{id}/details — extended lot info (separate request).
     * Currently returns banks from which seller accepts payments; structure is extensible for future arrays (e.g. remaining amount).
     */
    public function details(ExchangeLot $lot): JsonResponse
    {
        $lot->load(['banks']);
        $banks = $lot->banks->map(fn (Bank $bank) => [
            'id' => $bank->id,
            'name' => $bank->name,
            'swift_code' => $bank->swift_code,
        ])->values()->all();

        $data = [
            'banks' => $banks,
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/exchange-lots",
     *     summary="Create exchange lot",
     *     tags={"Exchange Lots"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"from_currency","to_currency","sell_amount","buy_amounts","crypto_currency_id","wallet_address"},
     *
     *             @OA\Property(property="from_currency", type="string", example="USD"),
     *             @OA\Property(property="to_currency", type="string", example="XRP"),
     *             @OA\Property(property="sell_amount", type="number", format="float"),
     *             @OA\Property(property="buy_amounts", type="array", @OA\Items(type="number")),
     *             @OA\Property(property="crypto_currency_id", type="integer"),
     *             @OA\Property(property="wallet_address", type="string"),
     *             @OA\Property(property="node_id", type="integer"),
     *             @OA\Property(property="bank_id", type="integer"),
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Created"),
     *     @OA\Response(response=401, description="Unauthorized"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'node_id' => 'required|integer|min:1',
            'from_currency' => 'required|string|max:20',
            'to_currency' => 'required|string|max:20',
            'sell_amount' => 'required|numeric|min:0',
            'buy_amounts' => 'required|array',
            'buy_amounts.*' => 'numeric|min:0',
            'rate' => 'nullable|numeric|min:0',
            'crypto_currency_id' => 'required|exists:crypto_currencies,id',
            'wallet_address' => 'required|string',
            'bank_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    $id = (int) $value;
                    if (SandboxVirtualBankService::isVirtualBankId($id)) {
                        if (! SandboxVirtualBankService::isSandboxApiHost($request->getHost())) {
                            $fail('Virtual sandbox bank is only available on sandbox API hosts.');
                        }

                        return;
                    }
                    if (! Bank::query()->where('id', $id)->exists()) {
                        $fail('The selected bank id is invalid.');
                    }
                },
            ],
            'bank_ids' => 'nullable|array',
            'bank_ids.*' => 'integer|exists:banks,id',
            'bank_account' => 'nullable|string|max:100',
            'payment_purpose' => 'nullable|string|max:255',
            'contract_url' => 'nullable|url|max:255',
            'is_for_individuals' => 'boolean',
            'is_for_legal_entities' => 'boolean',
            'is_test' => 'boolean',
            'test_group' => 'nullable|string|max:128',  // plain text from client, hashed before storing
            'main_group' => 'nullable|string|max:128',  // plain text from client, hashed before storing
            'note' => 'nullable|string|max:65535',
            'seller_locale' => 'required|string|max:10',
            'aml' => 'nullable|boolean',
            'buyer_payment_timeout_minutes' => \App\Support\BuyerPaymentTimeoutMinutes::validationRule(),
            'seller_notification_email' => 'nullable|string|email|max:255',
            'buyer_notification_email' => 'nullable|string|email|max:255',
            'expires_at' => 'nullable|date',
            'escrow_multisig_2_of_3_required' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        if (isset($validated['bank_id']) && (int) $validated['bank_id'] === SandboxVirtualBankService::virtualBankId()) {
            if (empty($validated['is_test'])) {
                return response()->json([
                    'success' => false,
                    'errors' => ['is_test' => ['Sandbox virtual bank requires is_test: true.']],
                ], 422);
            }
            $validated['virtual_sandbox_bank_code'] = SandboxVirtualBankService::bankCode();
            $validated['bank_id'] = null;
        } else {
            $validated['virtual_sandbox_bank_code'] = null;
        }
        $buyAmounts = array_values(array_filter(array_map('floatval', $validated['buy_amounts']), fn ($v) => $v > 0));
        if (empty($buyAmounts)) {
            return response()->json([
                'success' => false,
                'errors' => ['buy_amounts' => ['At least one positive amount is required.']],
            ], 422);
        }
        $validated['buy_amount_min'] = min($buyAmounts);
        $validated['buy_amount_max'] = max($buyAmounts);
        $validated['buy_amounts'] = $buyAmounts;

        // Rate: when single buy_amount — compute; when multiple — require from client
        if (count($buyAmounts) === 1) {
            $sellAmount = (float) $validated['sell_amount'];
            $validated['rate'] = $sellAmount > 0 ? ($buyAmounts[0] / $sellAmount) : 0;
        } else {
            $rate = isset($validated['rate']) ? (float) $validated['rate'] : null;
            if ($rate === null || $rate <= 0) {
                return response()->json([
                    'success' => false,
                    'errors' => ['rate' => ['Rate is required when multiple buy_amounts are specified.']],
                ], 422);
            }
            $validated['rate'] = $rate;
        }

        $fromCode = strtoupper($validated['from_currency']);
        $toCode = strtoupper($validated['to_currency']);
        $fromFiat = FiatCurrency::where('code', $fromCode)->first();
        $fromCrypto = $fromFiat ? null : CryptoCurrency::whereRaw('LOWER(code) = ?', [strtolower($fromCode)])->first();
        $toFiat = FiatCurrency::where('code', $toCode)->first();
        $toCrypto = $toFiat ? null : CryptoCurrency::whereRaw('LOWER(code) = ?', [strtolower($toCode)])->first();
        if (! $fromFiat && ! $fromCrypto) {
            return response()->json(['success' => false, 'errors' => ['from_currency' => ['Unknown currency code.']]], 422);
        }
        if (! $toFiat && ! $toCrypto) {
            return response()->json(['success' => false, 'errors' => ['to_currency' => ['Unknown currency code.']]], 422);
        }
        if ($fromFiat && $toFiat) {
            return response()->json([
                'success' => false,
                'errors' => ['to_currency' => ['Fiat to fiat exchange lots are not allowed.']],
            ], 422);
        }
        $reqMultisig = (bool) ($validated['escrow_multisig_2_of_3_required'] ?? false);
        $multisigErr = $this->validateEscrowMultisig2Of3LotRules($reqMultisig, $buyAmounts, $fromCrypto, $toCrypto);
        if ($multisigErr !== null) {
            return $multisigErr;
        }
        $validated['escrow_multisig_2_of_3_required'] = $reqMultisig;
        $validated['from_fiat_currency_id'] = $fromFiat?->id;
        $validated['from_crypto_currency_id'] = $fromCrypto?->id;
        $validated['to_fiat_currency_id'] = $toFiat?->id;
        $validated['to_crypto_currency_id'] = $toCrypto?->id;
        $decoded = json_decode($validated['wallet_address'], true);
        if (! is_array($decoded) || empty($decoded['token_hash'])) {
            return response()->json([
                'success' => false,
                'errors' => ['wallet_address' => ['token_hash is required in public part of escrow token.']],
            ], 422);
        }
        $validated['wallet_hash'] = strtolower(trim($decoded['token_hash']));

        // node_id in client catalogs can differ from local DB IDs on partner API.
        // Resolve node by token node_domain first (authoritative for multi-site),
        // fallback to provided node_id only when token domain is missing/unresolvable.
        $requestedNodeId = (int) ($validated['node_id'] ?? 0);
        $resolvedNode = null;
        $tokenNodeDomainRaw = isset($decoded['node_domain']) ? trim((string) $decoded['node_domain']) : '';
        if ($tokenNodeDomainRaw !== '') {
            $normalizeDomain = static function (string $domain): string {
                $v = strtolower(trim($domain));
                $v = preg_replace('#^https?://#', '', $v) ?? $v;
                $v = rtrim($v, '/');
                $v = str_replace('_', '.', $v);
                $v = preg_replace('/^(dev\.api\.|sandbox\.api\.|api\.)/i', '', $v) ?? $v;
                return trim((string) $v);
            };

            $tokenDomain = $normalizeDomain($tokenNodeDomainRaw);
            $resolvedNode = Node::query()
                ->where('is_active', true)
                ->get(['id', 'domain'])
                ->first(function (Node $node) use ($normalizeDomain, $tokenDomain): bool {
                    $nodeDomain = $normalizeDomain((string) ($node->domain ?? ''));
                    if ($nodeDomain === '' || $tokenDomain === '') {
                        return false;
                    }
                    return $nodeDomain === $tokenDomain
                        || str_ends_with($nodeDomain, '.'.$tokenDomain)
                        || str_ends_with($tokenDomain, '.'.$nodeDomain);
                });
        }

        if ($resolvedNode === null && $requestedNodeId > 0) {
            $resolvedNode = Node::query()->find($requestedNodeId);
        }

        if ($resolvedNode === null) {
            return response()->json([
                'success' => false,
                'errors' => ['node_id' => ['The selected node id is invalid.']],
            ], 422);
        }
        if ((int) $resolvedNode->id !== $requestedNodeId) {
            Log::warning('[exchange-lots] store node_id remapped by token domain', [
                'requested_node_id' => $requestedNodeId,
                'resolved_node_id' => (int) $resolvedNode->id,
                'token_node_domain' => $tokenNodeDomainRaw,
            ]);
        }
        $validated['node_id'] = (int) $resolvedNode->id;

        // Validate escrow token via Core before publishing lot (protection against fake tokens)
        $validation = $this->escrowTokenValidation->validateToken($validated['wallet_address']);
        if (! $validation['valid']) {
            return response()->json([
                'success' => false,
                'errors' => ['wallet_address' => [$validation['error'] ?? 'Escrow token validation failed. Token may be fake or invalid.']],
            ], 422);
        }

        // Store group hashes (SHA256) instead of plain text; null if no group
        $validated['test_group'] = ! empty($validated['test_group'])
            ? hash('sha256', trim($validated['test_group']))
            : null;
        $validated['main_group'] = ! empty($validated['main_group'])
            ? hash('sha256', trim($validated['main_group']))
            : null;
        $validated['buyer_payment_timeout_minutes'] = \App\Support\BuyerPaymentTimeoutMinutes::normalize(
            isset($validated['buyer_payment_timeout_minutes'])
                ? (int) $validated['buyer_payment_timeout_minutes']
                : null
        );
        $validated['seller_notification_email'] = ! empty($validated['seller_notification_email'])
            ? trim($validated['seller_notification_email'])
            : null;

        unset($validated['from_currency'], $validated['to_currency']);
        $bankIds = $validated['bank_ids'] ?? null;
        unset($validated['bank_ids']);

        // AML: read from raw JSON so "aml": null is never lost (has()/input() can drop null in some setups)
        $input = \is_array($request->all()) ? $request->all() : [];
        $raw = $request->getContent();
        Log::info('[exchange-lots] store AML debug', [
            'raw_length' => $raw === null ? 0 : strlen($raw),
            'raw_preview' => $raw ? substr($raw, 0, 200).(strlen($raw) > 200 ? '...' : '') : '',
            'input_has_aml' => array_key_exists('aml', $input),
            'input_aml_value' => array_key_exists('aml', $input) ? $input['aml'] : 'KEY_MISSING',
        ]);
        if ($raw !== '' && $raw !== null) {
            $decoded = json_decode($raw, true);
            Log::info('[exchange-lots] store AML from raw JSON', [
                'decoded_is_array' => \is_array($decoded),
                'has_aml_key' => \is_array($decoded) && array_key_exists('aml', $decoded),
                'aml_raw_value' => \is_array($decoded) && array_key_exists('aml', $decoded) ? $decoded['aml'] : 'N/A',
            ]);
            if (\is_array($decoded) && array_key_exists('aml', $decoded)) {
                $v = $decoded['aml'];
                $validated['aml'] = $this->normalizeNullableAmlFlag($v);
                Log::info('[exchange-lots] store AML set from raw', ['validated_aml' => $validated['aml']]);
            }
        } elseif (array_key_exists('aml', $input)) {
            $v = $input['aml'];
            $validated['aml'] = $this->normalizeNullableAmlFlag($v);
            Log::info('[exchange-lots] store AML set from input', ['validated_aml' => $validated['aml']]);
        }

        $lot = ExchangeLot::create($validated);
        Log::info('[exchange-lots] store lot created', ['lot_id' => $lot->id, 'lot_aml' => $lot->aml]);
        if (! empty($bankIds)) {
            $lot->banks()->sync(array_values(array_unique(array_map('intval', $bankIds))));
        }

        // Create or get mailbox for lot chat (IMAP) — see docs/LOT_MAILBOX_AND_IMAP_CHAT.md
        $mailbox = null;
        Log::info('[exchange-lots] Creating lot: before mailbox step', [
            'lot_id' => $lot->id,
            'wallet_hash_preview' => substr($validated['wallet_hash'] ?? '', 0, 16).'...',
        ]);
        try {
            $currencyForMailbox = self::coreCryptoTypeFromEscrowTokenJson($validated['wallet_address'])
                ?? ($lot->cryptoCurrency?->code ?? preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($decoded['currency'] ?? 'crypto'))) ?: 'crypto');
            Log::info('[exchange-lots] Calling ensureMailbox', ['lot_id' => $lot->id, 'currency' => $currencyForMailbox]);
            $mailbox = $this->lotMailboxService->ensureMailbox(
                $validated['wallet_address'],
                $validated['wallet_hash'],
                $currencyForMailbox
            );
            if ($mailbox) {
                Log::info('[exchange-lots] Lot mailbox created/retrieved', [
                    'lot_id' => $lot->id,
                    'email' => $mailbox['email'] ?? null,
                ]);
                $sellerEmail = $mailbox['email'] ?? null;
                if ($sellerEmail !== null && $sellerEmail !== '') {
                    $lot->update(['seller_lot_email' => $sellerEmail]);
                }
            } else {
                Log::debug('[exchange-lots] Lot mailbox not created (normalize failed or disabled)', ['lot_id' => $lot->id]);
            }
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] Lot mailbox creation failed (non-blocking)', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        $response = [
            'success' => true,
            'data' => \App\Http\Resources\ExchangeLotResource::make($lot->load(['cryptoCurrency', 'node', 'bank', 'fromFiatCurrency', 'fromCryptoCurrency', 'toFiatCurrency', 'toCryptoCurrency']))->resolve(),
        ];
        if ($mailbox) {
            $response['mailbox'] = $mailbox;
        }

        return response()->json($response, 201);
    }

    /**
     * @OA\Post(
     *     path="/exchange-lots/{id}/update",
     *     summary="Update exchange lot",
     *     tags={"Exchange Lots"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="from_currency", type="string"),
     *             @OA\Property(property="to_currency", type="string"),
     *             @OA\Property(property="sell_amount", type="number"),
     *             @OA\Property(property="buy_amounts", type="array", @OA\Items(type="number")),
     *             @OA\Property(property="bank_id", type="integer")
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Updated"),
     *     @OA\Response(response=401, description="Unauthorized"),
     *     @OA\Response(response=409, description="Lot has deal in progress; editing not allowed"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(Request $request, ExchangeLot $lot): JsonResponse
    {
        if (! empty($lot->buyer_lot_email)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot edit lot while a deal is in progress.',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'wallet_address' => 'required|string',
            'from_currency' => 'required|string|max:20',
            'to_currency' => 'required|string|max:20',
            'sell_amount' => 'required|numeric|min:0',
            'buy_amounts' => 'required|array',
            'buy_amounts.*' => 'numeric|min:0',
            'rate' => 'nullable|numeric|min:0',
            'bank_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    $id = (int) $value;
                    if (SandboxVirtualBankService::isVirtualBankId($id)) {
                        if (! SandboxVirtualBankService::isSandboxApiHost($request->getHost())) {
                            $fail('Virtual sandbox bank is only available on sandbox API hosts.');
                        }

                        return;
                    }
                    if (! Bank::query()->where('id', $id)->exists()) {
                        $fail('The selected bank id is invalid.');
                    }
                },
            ],
            'bank_ids' => 'nullable|array',
            'bank_ids.*' => 'integer|exists:banks,id',
            'test_group' => 'nullable|string|max:128',
            'main_group' => 'nullable|string|max:128',
            'note' => 'nullable|string|max:65535',
            'seller_locale' => 'nullable|string|max:10',
            'aml' => 'nullable|boolean',
            'buyer_payment_timeout_minutes' => \App\Support\BuyerPaymentTimeoutMinutes::validationRule(),
            'seller_notification_email' => 'nullable|string|email|max:255',
            'escrow_multisig_2_of_3_required' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $buyAmounts = array_values(array_filter(array_map('floatval', $validated['buy_amounts']), fn ($v) => $v > 0));
        if (empty($buyAmounts)) {
            return response()->json([
                'success' => false,
                'errors' => ['buy_amounts' => ['At least one positive amount is required.']],
            ], 422);
        }

        // Rate: when single buy_amount — compute; when multiple — require from client
        $rateValue = null;
        if (count($buyAmounts) === 1) {
            $sellAmount = (float) $validated['sell_amount'];
            $rateValue = $sellAmount > 0 ? ($buyAmounts[0] / $sellAmount) : 0;
        } else {
            $rateValue = isset($validated['rate']) ? (float) $validated['rate'] : null;
            if ($rateValue === null || $rateValue <= 0) {
                return response()->json([
                    'success' => false,
                    'errors' => ['rate' => ['Rate is required when multiple buy_amounts are specified.']],
                ], 422);
            }
        }

        // Validate incoming escrow token public part on update as well.
        $decoded = json_decode($validated['wallet_address'], true);
        if (! is_array($decoded) || empty($decoded['token_hash'])) {
            return response()->json([
                'success' => false,
                'errors' => ['wallet_address' => ['token_hash is required in public part of escrow token.']],
            ], 422);
        }
        $incomingHash = strtolower(trim($decoded['token_hash']));
        $validation = $this->escrowTokenValidation->validateToken($validated['wallet_address']);
        if (! $validation['valid']) {
            return response()->json([
                'success' => false,
                'errors' => ['wallet_address' => [$validation['error'] ?? 'Escrow token validation failed. Token may be fake or invalid.']],
            ], 422);
        }

        $fromCode = strtoupper($validated['from_currency']);
        $toCode = strtoupper($validated['to_currency']);
        $fromFiat = FiatCurrency::where('code', $fromCode)->first();
        $fromCrypto = $fromFiat ? null : CryptoCurrency::whereRaw('LOWER(code) = ?', [strtolower($fromCode)])->first();
        $toFiat = FiatCurrency::where('code', $toCode)->first();
        $toCrypto = $toFiat ? null : CryptoCurrency::whereRaw('LOWER(code) = ?', [strtolower($toCode)])->first();
        if (! $fromFiat && ! $fromCrypto) {
            return response()->json(['success' => false, 'errors' => ['from_currency' => ['Unknown currency code.']]], 422);
        }
        if (! $toFiat && ! $toCrypto) {
            return response()->json(['success' => false, 'errors' => ['to_currency' => ['Unknown currency code.']]], 422);
        }
        if ($fromFiat && $toFiat) {
            return response()->json([
                'success' => false,
                'errors' => ['to_currency' => ['Fiat to fiat exchange lots are not allowed.']],
            ], 422);
        }

        // Effective flag: DB value unless the client sends an update (prevents multi-tier buy_amounts on multisig lots when the key is omitted).
        $effectiveMultisig = (bool) ($lot->escrow_multisig_2_of_3_required ?? false);
        if ($request->exists('escrow_multisig_2_of_3_required')) {
            $effectiveMultisig = $request->boolean('escrow_multisig_2_of_3_required');
        }
        $multisigErr = $this->validateEscrowMultisig2Of3LotRules($effectiveMultisig, $buyAmounts, $fromCrypto, $toCrypto);
        if ($multisigErr !== null) {
            return $multisigErr;
        }

        $updateData = [
            'from_fiat_currency_id' => $fromFiat?->id,
            'from_crypto_currency_id' => $fromCrypto?->id,
            'to_fiat_currency_id' => $toFiat?->id,
            'to_crypto_currency_id' => $toCrypto?->id,
            'wallet_address' => $validated['wallet_address'],
            'wallet_hash' => $incomingHash,
            'sell_amount' => $validated['sell_amount'],
            'buy_amount_min' => min($buyAmounts),
            'buy_amount_max' => max($buyAmounts),
            'buy_amounts' => $buyAmounts,
            'rate' => $rateValue,
            // Editing/re-saving lot resets partial sale accumulator by request.
            'sold_crypto_amount' => 0,
        ];
        if ($request->exists('escrow_multisig_2_of_3_required')) {
            $updateData['escrow_multisig_2_of_3_required'] = $request->boolean('escrow_multisig_2_of_3_required');
        }
        if (array_key_exists('bank_id', $validated)) {
            $bid = $validated['bank_id'];
            if ($bid !== null && (int) $bid === SandboxVirtualBankService::virtualBankId()) {
                if (! SandboxVirtualBankService::isSandboxApiHost($request->getHost())) {
                    return response()->json([
                        'success' => false,
                        'errors' => ['bank_id' => ['Virtual sandbox bank is only available on sandbox API hosts.']],
                    ], 422);
                }
                if (! $lot->is_test) {
                    return response()->json([
                        'success' => false,
                        'errors' => ['bank_id' => ['Virtual sandbox bank is allowed only on test lots.']],
                    ], 422);
                }
                $updateData['bank_id'] = null;
                $updateData['virtual_sandbox_bank_code'] = SandboxVirtualBankService::bankCode();
            } else {
                $updateData['bank_id'] = $bid;
                $updateData['virtual_sandbox_bank_code'] = null;
            }
        }
        $updateData['test_group'] = ! empty($validated['test_group'] ?? null)
            ? hash('sha256', trim($validated['test_group']))
            : null;
        $updateData['main_group'] = ! empty($validated['main_group'] ?? null)
            ? hash('sha256', trim($validated['main_group']))
            : null;
        $updateData['note'] = $validated['note'] ?? null;
        if (array_key_exists('seller_locale', $validated) && $validated['seller_locale'] !== null) {
            $updateData['seller_locale'] = $validated['seller_locale'];
        }
        if (array_key_exists('buyer_payment_timeout_minutes', $validated)) {
            $updateData['buyer_payment_timeout_minutes'] = \App\Support\BuyerPaymentTimeoutMinutes::normalize(
                isset($validated['buyer_payment_timeout_minutes'])
                    ? (int) $validated['buyer_payment_timeout_minutes']
                    : null
            );
        }
        if (array_key_exists('seller_notification_email', $validated)) {
            $updateData['seller_notification_email'] = ! empty(trim($validated['seller_notification_email'] ?? ''))
                ? trim($validated['seller_notification_email'])
                : null;
        }
        // AML: read from raw JSON so "aml": null is never lost
        $raw = $request->getContent();
        $input = \is_array($request->all()) ? $request->all() : [];
        Log::info('[exchange-lots] update AML debug', [
            'lot_id' => $lot->id,
            'raw_length' => $raw === null ? 0 : strlen($raw),
            'raw_preview' => $raw ? substr($raw, 0, 200).(strlen($raw) > 200 ? '...' : '') : '',
            'input_has_aml' => array_key_exists('aml', $input),
            'input_aml_value' => array_key_exists('aml', $input) ? $input['aml'] : 'KEY_MISSING',
        ]);
        if ($raw !== '' && $raw !== null) {
            $decoded = json_decode($raw, true);
            Log::info('[exchange-lots] update AML from raw JSON', [
                'decoded_is_array' => \is_array($decoded),
                'has_aml_key' => \is_array($decoded) && array_key_exists('aml', $decoded),
                'aml_raw_value' => \is_array($decoded) && array_key_exists('aml', $decoded) ? $decoded['aml'] : 'N/A',
            ]);
            if (\is_array($decoded) && array_key_exists('aml', $decoded)) {
                $v = $decoded['aml'];
                $updateData['aml'] = $this->normalizeNullableAmlFlag($v);
                Log::info('[exchange-lots] update AML set from raw', ['updateData_aml' => $updateData['aml']]);
            }
        } else {
            if (array_key_exists('aml', $input)) {
                $v = $input['aml'];
                $updateData['aml'] = $this->normalizeNullableAmlFlag($v);
                Log::info('[exchange-lots] update AML set from input', ['updateData_aml' => $updateData['aml']]);
            }
        }
        $lot->update($updateData);
        Log::info('[exchange-lots] update done', ['lot_id' => $lot->id, 'lot_aml_after' => $lot->fresh()->aml]);

        try {
            $currencyForMailbox = self::coreCryptoTypeFromEscrowTokenJson($validated['wallet_address'])
                ?? ($lot->cryptoCurrency?->code ?? 'crypto');
            $mailbox = $this->lotMailboxService->ensureMailbox(
                $validated['wallet_address'],
                $incomingHash,
                $currencyForMailbox
            );
            $sellerEmail = $mailbox['email'] ?? null;
            if (! empty($sellerEmail)) {
                $lot->update(['seller_lot_email' => $sellerEmail]);
            }
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] Lot mailbox refresh failed on update (non-blocking)', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
            ]);
        }

        if (array_key_exists('bank_ids', $validated)) {
            $bankIds = $validated['bank_ids'] ?? [];
            $lot->banks()->sync(empty($bankIds) ? [] : array_values(array_unique(array_map('intval', $bankIds))));
        }

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\ExchangeLotResource::make($lot->load(['cryptoCurrency', 'node', 'bank', 'fromFiatCurrency', 'fromCryptoCurrency', 'toFiatCurrency', 'toCryptoCurrency'])),
        ]);
    }

    /**
     * @OA\Post(
     *     path="/exchange-lots/{id}/delete",
     *     summary="Delete exchange lot",
     *     tags={"Exchange Lots"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Deleted"),
     *     @OA\Response(response=401, description="Unauthorized"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function destroy(ExchangeLot $lot): JsonResponse
    {
        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
        if ($buyerEmail !== '') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete lot: a buyer has started a deal on this lot. Wait for the deal to end or for the buyer to exit.',
                'error_code' => 'lot_has_active_deal',
            ], 422);
        }

        $lot->delete();

        return response()->json([
            'success' => true,
            'message' => 'Lot successfully deleted',
        ]);
    }

    /**
     * @OA\Post(
     *     path="/exchange-lots/{id}/set-active",
     *     summary="Set lot active/inactive",
     *     tags={"Exchange Lots"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(required={"is_active"}, @OA\Property(property="is_active", type="boolean"))
     *     ),
     *
     *     @OA\Response(response=200, description="Updated"),
     *     @OA\Response(response=401, description="Unauthorized"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function setActive(Request $request, ExchangeLot $lot): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'is_active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $lot->update(['is_active' => $request->boolean('is_active')]);

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\ExchangeLotResource::make($lot->load(['cryptoCurrency', 'node', 'bank', 'fromFiatCurrency', 'fromCryptoCurrency', 'toFiatCurrency', 'toCryptoCurrency'])),
        ]);
    }

    /**
     * Send a message to the seller (lot owner). Used when buyer taps "Start deal".
     * Email is sent from noreply@ with app name (XBoard); suitable for a template, not for app chat.
     * Rejects if the requester is the seller (same escrow token / mailbox).
     *
     * @OA\Post(
     *     path="/exchange-lots/{lot}/send-message",
     *     summary="Send message to seller",
     *     tags={"Exchange Lots"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(required={"message"}, @OA\Property(property="message", type="string"))
     *     ),
     *
     *     @OA\Response(response=200, description="Message sent"),
     *     @OA\Response(response=409, description="start_deal: lot inactive, no remaining amount, lot taken, multisig misconfiguration"),
     *     @OA\Response(response=400, description="Cannot send to yourself / no seller mailbox"),
     *     @OA\Response(response=401, description="Unauthorized"),
     *     @OA\Response(response=404, description="Lot not found")
     * )
     */
    public function sendMessageToSeller(Request $request, ExchangeLot $lot): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:10000',
            'buyer_locale' => 'nullable|string|max:10',
            'selected_amount' => 'nullable|numeric|min:0',
            'message_type' => 'nullable|string|in:start_deal,buyer_exited',
            'deal_tag' => 'nullable|string|max:20000',
            'share_buyer_ip_with_seller' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $messageType = $request->input('message_type');
        $rawDealTagForMessageType = $request->input('deal_tag');
        $inferredMessageType = null;
        if ((! is_string($messageType) || trim($messageType) === '') && is_string($rawDealTagForMessageType) && trim($rawDealTagForMessageType) !== '') {
            $rawTagData = $this->decodeDealTagFromRaw($rawDealTagForMessageType);
            $rawTagType = is_array($rawTagData) ? trim((string) ($rawTagData['t'] ?? '')) : '';
            if (in_array($rawTagType, ['start_deal', 'buyer_exited'], true)) {
                $inferredMessageType = $rawTagType;
            }
        }
        if ($inferredMessageType !== null) {
            $messageType = $inferredMessageType;
            Log::info('[exchange-lots] sendMessageToSeller: message_type inferred from deal_tag', [
                'lot_id' => $lot->id ?? null,
                'message_type' => $messageType,
            ]);
        }

        $buyerEmailOnLot = trim((string) ($lot->buyer_lot_email ?? ''));
        // buyer_lot_email may be cleared while buyer_escrow_token_hash remains (chat auth after purge step);
        // bound buyer may still post deal_tag e.g. deal_completed_buyer.
        if ($buyerEmailOnLot === '' && $messageType !== 'start_deal' && ! $this->requesterIsBuyerForLot($request, $lot)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot start deal: message_type must be start_deal for a new buyer session.',
                'error_code' => 'start_deal_required',
            ], 422);
        }
        if ($buyerEmailOnLot === '' && ! $lot->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This lot is not active. The seller may have disabled or removed it.',
                'error_code' => 'lot_inactive',
            ], 409);
        }

        $lot->load(['cryptoCurrency', 'node', 'bank', 'fromFiatCurrency', 'fromCryptoCurrency', 'toFiatCurrency', 'toCryptoCurrency', 'banks']);
        $currency = self::sellerMailboxCryptoCode($lot);
        $sellerEmail = $this->lotMailboxService->getMailboxEmailForAddress($lot->wallet_address ?? '', $currency);
        // Fallback for exported/imported lots: use DB field when mailbox lookup fails (e.g. lot_mailboxes not imported)
        if (! $sellerEmail && ! empty($lot->seller_lot_email)) {
            $sellerEmail = (string) $lot->seller_lot_email;
            Log::info('[exchange-lots] sendMessageToSeller: using seller_lot_email from DB (mailbox lookup had no match)', [
                'lot_id' => $lot->id,
                'seller_lot_email' => $sellerEmail,
            ]);
        }

        if ($messageType === 'start_deal') {
            $wa = (string) ($lot->wallet_address ?? '');
            $sellerMailboxCurrency = self::coreCryptoTypeFromEscrowTokenJson($wa) ?? $currency;
            $sellerHash = strtolower(trim((string) ($lot->wallet_hash ?? '')));
            if ($sellerHash !== '' && $wa !== '') {
                try {
                    $sellerEnsured = $this->lotMailboxService->ensureMailbox($wa, $sellerHash, $sellerMailboxCurrency);
                    if (is_array($sellerEnsured) && ! empty($sellerEnsured['email'])) {
                        $sellerEmail = $sellerEnsured['email'];
                        $se = trim((string) $sellerEmail);
                        if ($se !== '') {
                            $lotSellerStored = trim((string) ($lot->seller_lot_email ?? ''));
                            if ($lotSellerStored === '' || strcasecmp($lotSellerStored, $se) !== 0) {
                                $lot->update(['seller_lot_email' => $se]);
                                $lot->seller_lot_email = $se;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('[exchange-lots] sendMessageToSeller: start_deal seller mailbox re-verify failed', [
                        'lot_id' => $lot->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            if ($sellerEmail === null || trim((string) $sellerEmail) === '') {
                $sellerEmail = $this->lotMailboxService->getMailboxEmailForAddress($wa, $sellerMailboxCurrency)
                    ?: $this->lotMailboxService->getMailboxEmailForAddress($wa, $currency);
            }
            if (($sellerEmail === null || trim((string) $sellerEmail) === '') && ! empty($lot->seller_lot_email)) {
                $sellerEmail = (string) $lot->seller_lot_email;
            }
            $sellerEmailTrimmed = trim((string) ($sellerEmail ?? ''));
            if ($sellerEmailTrimmed !== '' && ! $this->lotMailboxService->hasMailboxRowForEmail($sellerEmailTrimmed)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot start deal: seller mailbox is not provisioned in the mail system.',
                    'error_code' => 'seller_mailbox_not_provisioned',
                ], 503);
            }
        }

        Log::info('[exchange-lots] sendMessageToSeller: attempt', [
            'lot_id' => $lot->id,
            'wallet_address' => $lot->wallet_address,
            'currency' => $currency,
            'seller_email' => $sellerEmail ?: '(none)',
        ]);

        if (! $sellerEmail) {
            Log::warning('[exchange-lots] sendMessageToSeller: no seller mailbox', [
                'lot_id' => $lot->id,
                'wallet_address' => $lot->wallet_address,
                'currency' => $currency,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No seller mailbox for this lot. Cannot send message.',
                'error_code' => 'no_seller_mailbox',
            ], 400);
        }

        $tokenData = $request->input('escrow_token_data');
        $requesterWallet = '';
        $requesterCurrency = '';
        $requesterTokenHash = '';
        $requesterPublicPart = $request->header('X-Escrow-Token') ?? $request->input('escrow_token');
        $requesterMailboxEmail = null;
        $requesterCurrencyForMailbox = 'crypto';
        if (is_array($tokenData)) {
            $requesterWallet = $tokenData['wallet_address'] ?? '';
            $requesterCurrency = $tokenData['currency'] ?? '';
            $requesterTokenHash = trim((string) ($tokenData['token_hash'] ?? ''));
            $requesterCurrencyForMailbox = (is_string($requesterPublicPart) && $requesterPublicPart !== '')
                ? (self::coreCryptoTypeFromEscrowTokenJson($requesterPublicPart)
                    ?? (preg_replace('/[^a-z0-9_]/', '', strtolower($requesterCurrency)) ?: 'crypto'))
                : (preg_replace('/[^a-z0-9_]/', '', strtolower($requesterCurrency)) ?: 'crypto');
            if ($requesterWallet !== '' && $requesterCurrencyForMailbox !== '' && ! empty($requesterPublicPart) && $requesterTokenHash !== '') {
                try {
                    $requesterMailboxConfig = $this->lotMailboxService->ensureMailbox(
                        (string) $requesterPublicPart,
                        $requesterTokenHash,
                        $requesterCurrencyForMailbox
                    );
                    if (is_array($requesterMailboxConfig)) {
                        $requesterMailboxEmail = (string) ($requesterMailboxConfig['email'] ?? '');
                    }
                } catch (\Throwable $e) {
                    Log::warning('[exchange-lots] sendMessageToSeller: ensureMailbox for requester failed', [
                        'lot_id' => $lot->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            if (($requesterMailboxEmail === null || $requesterMailboxEmail === '') && $requesterWallet !== '' && $requesterCurrencyForMailbox !== '') {
                $requesterMailboxEmail = $this->lotMailboxService->getMailboxEmailForAddress($requesterWallet, $requesterCurrencyForMailbox);
                if ($requesterMailboxEmail !== null && strcasecmp($requesterMailboxEmail, $sellerEmail) === 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot send message to yourself. This is your lot.',
                        'error_code' => 'cannot_send_to_yourself',
                    ], 400);
                }
            }
        }
        // Buyer often sends only X-Escrow-Token after lot release cleared buyer_lot_email; resolve mailbox for mail + chat row.
        if (($requesterMailboxEmail === null || $requesterMailboxEmail === '') && is_string($requesterPublicPart) && trim($requesterPublicPart) !== '') {
            $hashFromHeader = DealBuyerEscrowTokenHash::fromRequest($request);
            if ($hashFromHeader !== '') {
                $currencyForMailbox = self::coreCryptoTypeFromEscrowTokenJson($requesterPublicPart)
                    ?? self::coreCryptoTypeForBuyerEscrow($lot);
                try {
                    $mailboxCfg = $this->lotMailboxService->ensureMailbox($requesterPublicPart, $hashFromHeader, $currencyForMailbox);
                    if (is_array($mailboxCfg) && ! empty($mailboxCfg['email'])) {
                        $requesterMailboxEmail = (string) $mailboxCfg['email'];
                    }
                } catch (\Throwable $e) {
                    Log::warning('[exchange-lots] sendMessageToSeller: ensureMailbox from header token failed', [
                        'lot_id' => $lot->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($messageType === 'start_deal') {
            $freshLotForStartDeal = ExchangeLot::query()->find($lot->id);
            if ($freshLotForStartDeal === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'This lot is no longer available.',
                    'error_code' => 'lot_not_found',
                ], 404);
            }
            $lot = $freshLotForStartDeal;
            $lot->load(['cryptoCurrency', 'node', 'bank', 'fromFiatCurrency', 'fromCryptoCurrency', 'toFiatCurrency', 'toCryptoCurrency', 'banks']);
            Log::info('[exchange-lots] sendMessageToSeller: start_deal request context', [
                'lot_id' => $lot->id,
                'seller_mailbox_email' => $sellerEmail,
                'seller_notification_email' => $lot->seller_notification_email,
                'buyer_notification_email_in_request' => $request->input('buyer_notification_email'),
                'requester_mailbox_email' => $requesterMailboxEmail,
            ]);
        }
        if ($messageType === 'start_deal' && ! $lot->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This lot is not active. The seller may have disabled it.',
                'error_code' => 'lot_inactive',
            ], 409);
        }
        if ($messageType === 'start_deal' && ($lot->escrow_multisig_2_of_3_required ?? false)) {
            $amounts = is_array($lot->buy_amounts) ? $lot->buy_amounts : [];
            if (count($amounts) !== 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Lot is misconfigured for 2-of-3 multisig escrow (expected exactly one buy amount).',
                    'error_code' => 'multisig_2_of_3_lot_misconfigured',
                ], 409);
            }
            $expected = (float) $amounts[0];
            $sel = $request->input('selected_amount');
            if (! is_numeric($sel)) {
                return response()->json([
                    'success' => false,
                    'message' => 'selected_amount is required and must match the lot buy amount for this multisig lot.',
                    'error_code' => 'multisig_2_of_3_selected_amount_required',
                ], 422);
            }
            $got = (float) $sel;
            $tol = max(1e-6, 1e-8 * max(abs($expected), abs($got), 1.0));
            if (abs($got - $expected) > $tol) {
                return response()->json([
                    'success' => false,
                    'message' => 'selected_amount must equal the lot\'s single buy amount (full lot only).',
                    'error_code' => 'multisig_2_of_3_selected_amount_mismatch',
                ], 422);
            }
        }
        if ($messageType === 'start_deal') {
            $remainingAmount = $lot->remaining_amount;
            if ($remainingAmount <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Lot has no remaining amount for a new deal.',
                    'error_code' => 'lot_no_remaining_amount',
                ], 409);
            }
            $availableAmounts = $lot->availableBuyAmountsForRemaining();
            if (count($availableAmounts) === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Lot has no available amount tiers for the current remaining volume.',
                    'error_code' => 'lot_no_available_tiers',
                ], 409);
            }
            $selectedAmount = $request->input('selected_amount');
            if (! is_numeric($selectedAmount)) {
                return response()->json([
                    'success' => false,
                    'message' => 'selected_amount is required.',
                    'error_code' => 'selected_amount_required',
                ], 422);
            }
            $selected = (float) $selectedAmount;
            $matchesAvailableTier = false;
            foreach ($availableAmounts as $allowed) {
                if (abs($selected - (float) $allowed) <= max(1e-6, 1e-8 * max(abs($selected), abs((float) $allowed), 1.0))) {
                    $matchesAvailableTier = true;
                    break;
                }
            }
            if (! $matchesAvailableTier) {
                return response()->json([
                    'success' => false,
                    'message' => 'selected_amount is not available for the lot remaining volume.',
                    'error_code' => 'selected_amount_not_available',
                ], 422);
            }
        }
        $buyerNotificationEmailForExternal = null;
        $buyerExternalSubject = $request->input('buyer_notification_subject');
        $buyerExternalBody = $request->input('buyer_notification_body');

        $startDealLockAcquired = false;
        $rollbackStartDealLock = function (string $reason) use (&$startDealLockAcquired, $messageType, $lot, $requesterMailboxEmail): void {
            if ($messageType !== 'start_deal' || ! $startDealLockAcquired || $requesterMailboxEmail === null || $requesterMailboxEmail === '') {
                return;
            }
            DB::table('exchange_lots')
                ->where('id', $lot->id)
                ->where('buyer_lot_email', $requesterMailboxEmail)
                ->update([
                    'buyer_lot_email' => null,
                    'buyer_escrow_token_hash' => null,
                    'buyer_selected_amount' => null,
                    'buyer_notification_email' => null,
                    'buyer_seen_ip' => null,
                    'buyer_ip_share_with_seller' => false,
                ]);
            $startDealLockAcquired = false;
            Log::warning('[exchange-lots] sendMessageToSeller: rolled back buyer lock after start_deal failure', [
                'lot_id' => $lot->id,
                'reason' => $reason,
                'buyer_lot_email' => $requesterMailboxEmail,
            ]);
        };

        // start_deal: atomically set buyer_lot_email only if currently NULL/empty (avoid race)
        // Also ensure buyer's mailbox exists; return mailbox config so client can read messages via IMAP
        $buyerMailboxConfig = null;
        if ($messageType === 'start_deal' && $requesterMailboxEmail !== null && $requesterMailboxEmail !== '') {
            $freshLotState = ExchangeLot::query()
                ->select(['id', 'is_active', 'buyer_lot_email'])
                ->find($lot->id);
            if ($freshLotState === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'This lot is no longer available.',
                    'error_code' => 'lot_not_found',
                ], 404);
            }
            if (! (bool) $freshLotState->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'This lot is not active. The seller may have disabled or removed it.',
                    'error_code' => 'lot_inactive',
                ], 409);
            }

            $updateData = [
                'buyer_lot_email' => $requesterMailboxEmail,
            ];
            $buyerNotificationEmail = $request->input('buyer_notification_email');
            if (is_string($buyerNotificationEmail)) {
                $buyerNotificationEmail = trim($buyerNotificationEmail);
                if ($buyerNotificationEmail !== '' && filter_var($buyerNotificationEmail, FILTER_VALIDATE_EMAIL)) {
                    $updateData['buyer_notification_email'] = $buyerNotificationEmail;
                    $buyerNotificationEmailForExternal = $buyerNotificationEmail;
                }
            }
            $selectedAmount = $request->input('selected_amount');
            if (is_numeric($selectedAmount) && (float) $selectedAmount > 0) {
                $updateData['buyer_selected_amount'] = (float) $selectedAmount;
            }
            $tokenHash = trim((string) $requesterTokenHash);
            if ($tokenHash !== '' && strlen($tokenHash) <= 64) {
                $updateData['buyer_escrow_token_hash'] = strtolower($tokenHash);
            }
            $updated = DB::table('exchange_lots')
                ->where('id', $lot->id)
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('buyer_lot_email')->orWhere('buyer_lot_email', '');
                })
                ->update($updateData);
            if ($updated === 1) {
                $startDealLockAcquired = true;
                $this->resetLotDealChatAndMailboxesForNewDeal($lot, $requesterMailboxEmail);
                $lot->refresh();
            }
            if ($updated === 0) {
                $freshAfterFailedLock = ExchangeLot::query()
                    ->select(['id', 'is_active', 'buyer_lot_email'])
                    ->find($lot->id);
                if ($freshAfterFailedLock === null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This lot is no longer available.',
                        'error_code' => 'lot_not_found',
                    ], 404);
                }
                if (! (bool) $freshAfterFailedLock->is_active) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This lot is not active. The seller may have disabled or removed it.',
                        'error_code' => 'lot_inactive',
                    ], 409);
                }
                // Idempotent resume for same buyer mailbox: refresh selected amount/hash on existing deal.
                $sameBuyer = ExchangeLot::query()
                    ->where('id', $lot->id)
                    ->whereRaw('LOWER(TRIM(buyer_lot_email)) = ?', [strtolower(trim($requesterMailboxEmail))])
                    ->exists();
                if ($sameBuyer) {
                    DB::table('exchange_lots')
                        ->where('id', $lot->id)
                        ->update($updateData);
                    Log::info('[exchange-lots] sendMessageToSeller: start_deal resumed for same buyer', [
                        'lot_id' => $lot->id,
                        'buyer_lot_email' => $requesterMailboxEmail,
                    ]);
                } else {
                    Log::warning('[exchange-lots] sendMessageToSeller: lot already has buyer', ['lot_id' => $lot->id]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Another buyer has already started a deal on this lot.',
                        'error_code' => 'lot_taken',
                    ], 409);
                }
            }
            if (! empty($requesterPublicPart) && is_string($requesterPublicPart) && $tokenHash !== '') {
                try {
                    $buyerMailboxConfig = $this->lotMailboxService->ensureMailbox($requesterPublicPart, $tokenHash, $requesterCurrencyForMailbox);
                } catch (\Throwable $e) {
                    Log::warning('[exchange-lots] sendMessageToSeller: ensureMailbox for buyer failed (non-blocking)', [
                        'lot_id' => $lot->id,
                        'buyer_email' => $requesterMailboxEmail,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            $buyerEmailTrimmed = trim((string) $requesterMailboxEmail);
            if ($buyerEmailTrimmed !== '' && ! $this->lotMailboxService->hasMailboxRowForEmail($buyerEmailTrimmed)) {
                $rollbackStartDealLock('buyer_mailbox_row_missing_after_ensure');

                return response()->json([
                    'success' => false,
                    'message' => 'Cannot start deal: buyer mailbox is not provisioned in the mail system.',
                    'error_code' => 'buyer_mailbox_not_provisioned',
                ], 503);
            }
            self::syncBuyerIpOnExchangeLot((int) $lot->id, $request);
            Log::info('[exchange-lots] sendMessageToSeller: buyer_lot_email set', ['lot_id' => $lot->id, 'buyer_lot_email' => $requesterMailboxEmail]);
        } elseif ($messageType === 'start_deal') {
            Log::warning('[exchange-lots] sendMessageToSeller: cannot lock lot, requester mailbox missing', [
                'lot_id' => $lot->id,
                'has_wallet' => $requesterWallet !== '',
                'has_currency' => $requesterCurrency !== '',
                'has_token_hash' => $requesterTokenHash !== '',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Cannot start deal: buyer mailbox is unavailable.',
                'error_code' => 'buyer_mailbox_unavailable',
            ], 400);
        }

        // buyer_exited: delete reservation in Core, then delete transactions and clear buyer_lot_email.
        // Run when we have requesterMailboxEmail (from escrow_token_data) OR when we have buyer token that matches lot (client often sends only X-Escrow-Token).
        $buyerExitedEmailToClear = null;
        if ($messageType === 'buyer_exited') {
            if ($requesterMailboxEmail !== null && $requesterMailboxEmail !== '') {
                $buyerExitedEmailToClear = $requesterMailboxEmail;
            } else {
                $buyerToken = $request->input('escrow_token') ?? $request->header('X-Escrow-Token');
                if (! empty($buyerToken) && is_string($buyerToken)) {
                    $decoded = json_decode($buyerToken, true);
                    $tokenHash = isset($decoded['token_hash']) ? trim((string) $decoded['token_hash']) : '';
                    $storedHash = $lot->buyer_escrow_token_hash ? trim((string) $lot->buyer_escrow_token_hash) : '';
                    if ($tokenHash !== '' && $storedHash !== '' && hash_equals(strtolower($storedHash), strtolower($tokenHash))) {
                        $buyerExitedEmailToClear = $lot->buyer_lot_email;
                    }
                }
            }
        }
        if ($buyerExitedEmailToClear !== null) {
            $buyerToken = $request->input('escrow_token') ?? $request->header('X-Escrow-Token');
            if (! empty($buyerToken) && is_string($buyerToken)) {
                try {
                    [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($buyerToken);
                    try {
                        $cryptoType = self::coreCryptoTypeFromEscrowTokenJson($buyerToken)
                            ?? self::coreCryptoTypeForBuyerEscrow($lot);
                    } catch (\InvalidArgumentException) {
                        $cryptoType = strtolower((string) ($lot->cryptoCurrency?->code ?? 'xmr'));
                    }
                    $timeout = (int) config('services.core.timeout', 10);
                    $req = Http::timeout($timeout);
                    if ($coreKey !== '') {
                        $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
                    }
                    $resp = $req->post(rtrim($coreUrl, '/').'/api/delete-reservation-by-buyer', [
                        'exchange_lot_id' => (string) $lot->id,
                        'escrow_token' => $buyerToken,
                        'crypto_type' => $cryptoType,
                    ]);
                    if ($resp->successful()) {
                        Log::info('[exchange-lots] sendMessageToSeller: buyer_exited Core delete-reservation-by-buyer OK', ['lot_id' => $lot->id]);
                    } else {
                        Log::warning('[exchange-lots] sendMessageToSeller: buyer_exited Core delete-reservation-by-buyer failed', [
                            'lot_id' => $lot->id,
                            'status' => $resp->status(),
                            'body' => $resp->body(),
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('[exchange-lots] sendMessageToSeller: buyer_exited Core delete-reservation-by-buyer exception', [
                        'lot_id' => $lot->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            $deleted = Transaction::where('exchange_lot_id', $lot->id)
                ->where('buyer_lot_email', $buyerExitedEmailToClear)
                ->delete();
            Log::info('[exchange-lots] sendMessageToSeller: buyer_exited transactions deleted', ['lot_id' => $lot->id, 'deleted' => $deleted]);
            DB::table('exchange_lots')
                ->where('id', $lot->id)
                ->where('buyer_lot_email', $buyerExitedEmailToClear)
                ->update([
                    'buyer_lot_email' => null,
                    'buyer_escrow_token_hash' => null,
                    'buyer_seen_ip' => null,
                    'buyer_ip_share_with_seller' => false,
                ]);
            $this->closeOpenDisputesForLot(
                lot: $lot,
                resolvedBy: 'system',
                resolution: 'Deal was exited by buyer.'
            );
            Log::info('[exchange-lots] sendMessageToSeller: buyer_lot_email cleared', ['lot_id' => $lot->id]);
        }

        // Send from noreply@ with app name (XBoard) — template email, not shown in app chat
        $fromAddress = config('mail.from.address', 'noreply@xdexboard.com');
        $fromName = config('mail.from.name', 'XBoard');

        $messageText = $request->input('message', '');
        $subject = $this->getDealMessageSubject($lot);
        $body = $messageText;

        Log::info('[exchange-lots] sendMessageToSeller: sending mail', [
            'lot_id' => $lot->id,
            'to' => $sellerEmail,
            'from' => $fromAddress,
            'subject' => $subject,
            'message_length' => strlen($messageText),
        ]);

        try {
            $tagJson = null;
            $customDealTagData = null;
            $customDealTagRaw = $request->input('deal_tag');
            if (is_string($customDealTagRaw) && trim($customDealTagRaw) !== '') {
                $customDealTagData = $this->decodeDealTagFromRaw($customDealTagRaw);
                if ($customDealTagData === null) {
                    Log::warning('[exchange-lots] sendMessageToSeller: invalid custom deal_tag JSON', [
                        'lot_id' => $lot->id,
                        'deal_tag_preview' => substr($customDealTagRaw, 0, 300),
                    ]);
                }
            }
            // Never let client-supplied deal_tag override control-plane tags (would allow skipping steps).
            if (in_array($messageType, ['start_deal', 'buyer_exited'], true)) {
                $customDealTagData = null;
            }
            if ($messageType === 'buyer_exited') {
                $tagJson = json_encode(['t' => 'buyer_exited', 'lot_id' => $lot->id]);
            } elseif ($messageType === 'start_deal') {
                $tag = ['t' => 'start_deal', 'lot_id' => $lot->id];
                $selectedAmount = $request->input('selected_amount');
                if (is_numeric($selectedAmount) && (float) $selectedAmount > 0) {
                    $tag['selected_amount'] = (float) $selectedAmount;
                }
                $locale = $request->input('buyer_locale');
                if (is_string($locale) && $locale !== '') {
                    $tag['buyer_locale'] = $locale;
                }
                $tagJson = json_encode($tag);
            }
            if ($customDealTagData !== null) {
                $receiptLinkReplacements = [];
                if (! array_key_exists('lot_id', $customDealTagData)) {
                    $customDealTagData['lot_id'] = (int) $lot->id;
                }
                $customDealTagData = $this->sanitizeIncomingCustomDealTag($customDealTagData);
                $customDealTagData = $this->promoteReceiptLinksInDealTag($customDealTagData, $receiptLinkReplacements);
                if (count($receiptLinkReplacements) > 0) {
                    $body = strtr($body, $receiptLinkReplacements);
                }
                $lot->refresh();
                $seqErr = DealChatTagSequenceValidator::validateBuyerCustomDealTag($lot, $customDealTagData);
                if ($seqErr !== null) {
                    return response()->json([
                        'success' => false,
                        'message' => $seqErr['message'],
                        'error_code' => $seqErr['error_code'],
                    ], 422);
                }
                $coreTxCheck = $this->verifyCryptoTxDealTagViaCore($lot, $customDealTagData);
                if (($coreTxCheck['error'] ?? null) !== null) {
                    /** @var array{message:string,error_code:string,status?:int,verification?:array<string,mixed>} $err */
                    $err = $coreTxCheck['error'];
                    return response()->json([
                        'success' => false,
                        'message' => $err['message'],
                        'error_code' => $err['error_code'],
                        'verification' => $err['verification'] ?? null,
                    ], (int) ($err['status'] ?? 422));
                }
                $customDealTagData = $coreTxCheck['deal_tag'] ?? $customDealTagData;
                $receiptReuseCheck = $this->validateAndAttachReceiptChecksums(
                    lot: $lot,
                    senderSide: 'buyer',
                    dealTagData: $customDealTagData
                );
                if (($receiptReuseCheck['error'] ?? null) !== null) {
                    /** @var array{message:string,error_code:string,status?:int} $err */
                    $err = $receiptReuseCheck['error'];
                    return response()->json([
                        'success' => false,
                        'message' => $err['message'],
                        'error_code' => $err['error_code'],
                    ], (int) ($err['status'] ?? 422));
                }
                $customDealTagData = $receiptReuseCheck['deal_tag'] ?? $customDealTagData;
                $disputeOpenCheck = $this->validateDisputeOpenGuard(
                    lot: $lot,
                    senderSide: 'buyer',
                    dealTagData: $customDealTagData
                );
                if ($disputeOpenCheck !== null) {
                    return response()->json([
                        'success' => false,
                        'message' => $disputeOpenCheck['message'],
                        'error_code' => $disputeOpenCheck['error_code'],
                    ], (int) ($disputeOpenCheck['status'] ?? 422));
                }
                $tagJson = json_encode($customDealTagData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $this->sendDealEmail(
                to: $sellerEmail,
                subject: $subject,
                fromAddress: $fromAddress,
                fromName: $fromName,
                body: $body,
                tagJson: $tagJson
            );
            if ($messageType === 'start_deal') {
                $sellerNotificationEmail = $lot->seller_notification_email ? trim((string) $lot->seller_notification_email) : null;
                if (! $sellerNotificationEmail || ! filter_var($sellerNotificationEmail, FILTER_VALIDATE_EMAIL)) {
                    $rollbackStartDealLock('seller_notification_email_missing_or_invalid');

                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot start deal: seller external notification email is not configured.',
                        'error_code' => 'seller_external_email_required',
                    ], 409);
                }
                Log::info('[exchange-lots] sendMessageToSeller: start_deal seller external candidate', [
                    'lot_id' => $lot->id,
                    'to' => $sellerNotificationEmail,
                    'seller_mailbox_email' => $sellerEmail,
                ]);
                $sellerLimitAllowed = $this->incrementEmailTestCounter($sellerNotificationEmail);
                if (! $sellerLimitAllowed) {
                    $rollbackStartDealLock('seller_external_email_daily_limit');

                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot start deal: seller external email daily limit reached.',
                        'error_code' => 'seller_external_email_daily_limit_reached',
                    ], 429);
                }
                $clientWebOriginRaw = $request->input('client_web_origin');
                $links = $this->buildOpenDealEmailLinks($clientWebOriginRaw, $this->sellerOpenDealQueryParams($lot));
                $clientWebUrl = $links['clientWebUrl'];
                $webUrl = $links['appLandingUrl'];
                $appIntentUrl = $links['appIntentUrl'];
                $appUrl = $links['appUrl'];
                try {
                    if ($webUrl !== null || $appUrl !== null || $clientWebUrl !== null) {
                        $plainForExternal = $this->appendOpenDealPlainTextLinks($body, $clientWebUrl, $appIntentUrl, $webUrl, $appUrl);
                        $htmlForExternal = $this->buildSellerExternalNotificationHtml($body, $webUrl, $clientWebUrl, $appIntentUrl, $appUrl);
                        $this->sendDealEmailHtmlMultipart(
                            to: $sellerNotificationEmail,
                            subject: $subject,
                            fromAddress: $fromAddress,
                            fromName: $fromName,
                            plainBody: $plainForExternal,
                            htmlBody: $htmlForExternal,
                            tagJson: $tagJson
                        );
                    } else {
                        $this->sendDealEmail(
                            to: $sellerNotificationEmail,
                            subject: $subject,
                            fromAddress: $fromAddress,
                            fromName: $fromName,
                            body: $body,
                            tagJson: $tagJson
                        );
                    }
                    Log::info('[exchange-lots] sendMessageToSeller: start_deal external seller notification sent', [
                        'lot_id' => $lot->id,
                        'to' => $sellerNotificationEmail,
                    ]);
                } catch (\Throwable $notificationError) {
                    Log::warning('[exchange-lots] sendMessageToSeller: start_deal external seller notification failed', [
                        'lot_id' => $lot->id,
                        'to' => $sellerNotificationEmail,
                        'error' => $notificationError->getMessage(),
                    ]);
                    $rollbackStartDealLock('seller_external_email_send_failed');

                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot start deal: failed to send seller external notification email.',
                        'error_code' => 'seller_external_email_send_failed',
                    ], 502);
                }

                // Optional external notification for buyer: "you entered the deal, seller is notified", with deep links.
                // Use ONLY the email from this request ($buyerNotificationEmailForExternal). Do not fall back to
                // $lot->buyer_notification_email: that column can still hold a previous buyer's address (e.g. removed
                // from the device) when the client omits buyer_notification_email — which would send to the wrong mailbox.
                $buyerExternalEmail = $buyerNotificationEmailForExternal;
                if ($buyerExternalEmail !== null) {
                    Log::info('[exchange-lots] sendMessageToSeller: start_deal buyer external candidate', [
                        'lot_id' => $lot->id,
                        'to' => $buyerExternalEmail,
                    ]);
                    $buyerLimitAllowed = $this->incrementEmailTestCounter($buyerExternalEmail);
                    if (! $buyerLimitAllowed) {
                        Log::warning('[exchange-lots] sendMessageToSeller: start_deal external buyer notification skipped (daily limit)', [
                            'lot_id' => $lot->id,
                            'to' => $buyerExternalEmail,
                        ]);
                    } else {
                        $links = $this->buildOpenDealEmailLinks($clientWebOriginRaw, $this->sellerOpenDealQueryParams($lot));
                        $clientWebUrl = $links['clientWebUrl'];
                        $webUrl = $links['appLandingUrl'];
                        $appIntentUrl = $links['appIntentUrl'];
                        $appUrl = $links['appUrl'];
                        try {
                            if ($webUrl !== null || $appUrl !== null || $clientWebUrl !== null) {
                                $buyerPlain = is_string($buyerExternalBody) && trim($buyerExternalBody) !== ''
                                    ? trim($buyerExternalBody)
                                    : 'You have opened the deal window for this lot. The seller has been notified about your entry.';
                                $buyerPlainWithLinks = $this->appendOpenDealPlainTextLinks($buyerPlain, $clientWebUrl, $appIntentUrl, $webUrl, $appUrl);
                                $buyerHtml = $this->buildSellerExternalNotificationHtml($buyerPlain, $webUrl, $clientWebUrl, $appIntentUrl, $appUrl);
                                $this->sendDealEmailHtmlMultipart(
                                    to: $buyerExternalEmail,
                                    subject: is_string($buyerExternalSubject) && trim($buyerExternalSubject) !== ''
                                        ? trim($buyerExternalSubject)
                                        : $subject,
                                    fromAddress: $fromAddress,
                                    fromName: $fromName,
                                    plainBody: $buyerPlainWithLinks,
                                    htmlBody: $buyerHtml,
                                    tagJson: $tagJson
                                );
                            } else {
                                $buyerPlain = is_string($buyerExternalBody) && trim($buyerExternalBody) !== ''
                                    ? trim($buyerExternalBody)
                                    : 'You have opened the deal window for this lot. The seller has been notified about your entry.';
                                $this->sendDealEmail(
                                    to: $buyerExternalEmail,
                                    subject: is_string($buyerExternalSubject) && trim($buyerExternalSubject) !== ''
                                        ? trim($buyerExternalSubject)
                                        : $subject,
                                    fromAddress: $fromAddress,
                                    fromName: $fromName,
                                    body: $buyerPlain,
                                    tagJson: $tagJson
                                );
                            }
                            Log::info('[exchange-lots] sendMessageToSeller: start_deal external buyer notification sent', [
                                'lot_id' => $lot->id,
                                'to' => $buyerExternalEmail,
                            ]);
                        } catch (\Throwable $buyerNotifyError) {
                            Log::warning('[exchange-lots] sendMessageToSeller: start_deal external buyer notification failed', [
                                'lot_id' => $lot->id,
                                'to' => $buyerExternalEmail,
                                'error' => $buyerNotifyError->getMessage(),
                            ]);
                        }
                    }
                }
            }
            $this->storeReceiptLinksInLatestTransaction($lot, $customDealTagData);
            if ($requesterMailboxEmail !== null && $requesterMailboxEmail !== '') {
                $this->lotMailboxService->writeBuyerSentCopyToMailbox($requesterMailboxEmail, (string) $lot->id, $body);
            }
            $chatPersisted = $this->insertDealChatMessage(
                $lot,
                'buyer',
                $requesterMailboxEmail ?? config('mail.from.address'),
                $sellerEmail,
                $body,
                $tagJson
            );
            $this->syncDisputeStateFromDealTag(
                lot: $lot,
                senderSide: 'buyer',
                dealTagData: $customDealTagData,
                messageType: $messageType
            );
            $completionError = $this->finalizeAmicableDealCompletionIfNeeded($lot, $request, $customDealTagData, $chatPersisted);
            if ($completionError !== null) {
                return $completionError;
            }
            Log::info('[exchange-lots] sendMessageToSeller: mail sent successfully', [
                'lot_id' => $lot->id,
                'to' => $sellerEmail,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] sendMessageToSeller: failed to send mail', [
                'lot_id' => $lot->id,
                'to' => $sellerEmail,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            Log::debug('[exchange-lots] sendMessageToSeller: exception trace', [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send message: '.$e->getMessage(),
            ], 500);
        }

        $response = [
            'success' => true,
            'message' => 'Message sent to seller.',
        ];
        if ($buyerMailboxConfig !== null) {
            $response['mailbox'] = $buyerMailboxConfig;
        }

        return response()->json($response);
    }

    /**
     * Send a test "buyer entered deal" notification. Client sends localized subject and body (no server-side localization).
     *
     * @OA\Post(
     *     path="/exchange-lots/{lot}/send-test-notification-email",
     *     summary="Send test notification email (seller only)",
     *     tags={"Exchange Lots"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\RequestBody(@OA\JsonContent(
     *         required={"body"},
     *
     *         @OA\Property(property="to_email", type="string"),
     *         @OA\Property(property="subject", type="string"),
     *         @OA\Property(property="body", type="string", description="Localized message body from client")
     *     )),
     *
     *     @OA\Response(response=200, description="Test email sent"),
     *     @OA\Response(response=400, description="No recipient email"),
     *     @OA\Response(response=422, description="Missing body")
     * )
     */
    public function sendTestNotificationEmail(Request $request, ExchangeLot $lot): JsonResponse
    {
        $toEmail = $request->input('to_email');
        if (is_string($toEmail) && trim($toEmail) !== '') {
            $toEmail = trim($toEmail);
        } else {
            $toEmail = $lot->seller_notification_email ? trim((string) $lot->seller_notification_email) : null;
        }
        if (! $toEmail || ! filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid recipient email. Set to_email in request or lot.seller_notification_email.',
            ], 400);
        }

        // Only allow test emails to addresses that were previously verified via token creation.
        if (! $this->isVerifiedEmail($toEmail)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot send test email to this address. Use it for token creation and verification first.',
                'error_code' => 'unverified_email_for_test',
            ], 422);
        }

        // Per-recipient daily rate limit to avoid abuse of test emails.
        if (! $this->incrementEmailTestCounter($toEmail)) {
            return response()->json([
                'success' => false,
                'message' => 'Test email daily limit reached for this recipient.',
                'error_code' => 'test_email_limit_reached',
            ], 429);
        }

        // Ensure node relation is available for deep-link helpers (sellerOpenDealQueryParams uses $lot->node->domain).
        $lot->loadMissing('node');

        $body = $request->input('body');
        if (! is_string($body) || trim($body) === '') {
            return response()->json(['success' => false, 'message' => 'Body is required.'], 422);
        }
        $body = trim($body);
        $subject = $request->input('subject');
        $subject = is_string($subject) && trim($subject) !== '' ? trim($subject) : sprintf('Deal message — Lot #%s', (string) $lot->id);

        $clientWebOriginRaw = $request->input('client_web_origin');
        $links = ['clientWebUrl' => null, 'appLandingUrl' => null, 'appIntentUrl' => null, 'appUrl' => null];
        try {
            $links = $this->buildOpenDealEmailLinks($clientWebOriginRaw, $this->sellerOpenDealQueryParams($lot));
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] sendTestNotificationEmail: failed to build deep links', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
            ]);
        }
        $clientWebUrl = $links['clientWebUrl'];
        $webUrl = $links['appLandingUrl'];
        $appIntentUrl = $links['appIntentUrl'];
        $appLink = $links['appUrl'];

        $fromAddress = config('mail.from.address', 'noreply@xdexboard.com');
        $fromName = config('mail.from.name', 'XBoard');
        $tagJson = json_encode(['t' => 'start_deal', 'lot_id' => $lot->id]);

        $plainBody = ($webUrl !== null || $appLink !== null || $clientWebUrl !== null)
            ? $this->appendOpenDealPlainTextLinks($body, $clientWebUrl, $appIntentUrl, $webUrl, $appLink)
            : $body;
        $htmlBody = $this->buildSellerExternalNotificationHtml($body, $webUrl, $clientWebUrl, $appIntentUrl, $appLink);

        try {
            $this->sendDealEmailHtmlMultipart(
                to: $toEmail,
                subject: $subject,
                fromAddress: $fromAddress,
                fromName: $fromName,
                plainBody: $plainBody,
                htmlBody: $htmlBody,
                tagJson: $tagJson
            );
            Log::info('[exchange-lots] sendTestNotificationEmail: sent', ['lot_id' => $lot->id, 'to' => $toEmail]);

            return response()->json(['success' => true, 'message' => 'Test notification email sent.']);
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] sendTestNotificationEmail: failed', [
                'lot_id' => $lot->id,
                'to' => $toEmail,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send test email: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send a test "buyer entered deal" notification when no lot is selected (e.g. create form). Client sends localized subject and body.
     * POST /exchange-lots/send-test-notification-email with body { to_email, body, subject? }.
     */
    public function sendTestNotificationEmailStandalone(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'to_email' => 'required|email',
            'body' => 'required|string|max:10000',
            'subject' => 'nullable|string|max:255',
            'client_web_origin' => 'nullable|string|max:256',
            'open_deal_lot_id' => 'nullable|string|max:64',
            'open_deal_node_key' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        $toEmail = trim((string) $request->input('to_email'));

        // Only allow test emails to addresses that were previously verified via token creation.
        if (! $this->isVerifiedEmail($toEmail)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot send test email to this address. Use it for token creation and verification first.',
                'error_code' => 'unverified_email_for_test',
            ], 422);
        }

        // Apply the same daily limit to standalone test endpoint.
        if (! $this->incrementEmailTestCounter($toEmail)) {
            return response()->json([
                'success' => false,
                'message' => 'Test email daily limit reached for this recipient.',
                'error_code' => 'test_email_limit_reached',
            ], 429);
        }

        $body = trim((string) $request->input('body'));
        $subject = $request->input('subject');
        $subject = is_string($subject) && trim($subject) !== '' ? trim($subject) : 'Deal message — Lot #test';

        $clientWebOriginRaw = $request->input('client_web_origin');
        $openDealLotId = $request->input('open_deal_lot_id');
        $openDealNodeKey = $request->input('open_deal_node_key');
        $links = $this->buildOpenDealEmailLinks(
            $clientWebOriginRaw,
            $this->openDealQueryParamsFromInputs(
                is_string($openDealLotId) ? $openDealLotId : null,
                is_string($openDealNodeKey) ? $openDealNodeKey : null
            )
        );
        $clientWebUrl = $links['clientWebUrl'];
        $webUrl = $links['appLandingUrl'];
        $appIntentUrl = $links['appIntentUrl'];
        $appLink = $links['appUrl'];

        $fromAddress = config('mail.from.address', 'noreply@xdexboard.com');
        $fromName = config('mail.from.name', 'XBoard');
        $tagJson = json_encode(['t' => 'start_deal', 'lot_id' => 'test']);

        $plainBody = ($webUrl !== null || $appLink !== null || $clientWebUrl !== null)
            ? $this->appendOpenDealPlainTextLinks($body, $clientWebUrl, $appIntentUrl, $webUrl, $appLink)
            : $body;
        $htmlBody = $this->buildSellerExternalNotificationHtml($body, $webUrl, $clientWebUrl, $appIntentUrl, $appLink);

        try {
            $this->sendDealEmailHtmlMultipart(
                to: $toEmail,
                subject: $subject,
                fromAddress: $fromAddress,
                fromName: $fromName,
                plainBody: $plainBody,
                htmlBody: $htmlBody,
                tagJson: $tagJson
            );
            Log::info('[exchange-lots] sendTestNotificationEmailStandalone: sent', ['to' => $toEmail]);

            return response()->json(['success' => true, 'message' => 'Test notification email sent.']);
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] sendTestNotificationEmailStandalone: failed', ['to' => $toEmail, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send test email: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send a message from the seller (lot owner) to the buyer.
     * Only the seller can call this; email is delivered to the buyer's lot mailbox (buyer_lot_email).
     *
     * @OA\Post(
     *     path="/exchange-lots/{lot}/send-message-to-buyer",
     *     summary="Send message to buyer (seller only)",
     *     tags={"Exchange Lots"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"message"}, @OA\Property(property="message", type="string"))),
     *
     *     @OA\Response(response=200, description="Message sent"),
     *     @OA\Response(response=400, description="Not seller / no buyer mailbox"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function sendMessageToBuyer(Request $request, ExchangeLot $lot): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:10000',
            'message_type' => 'nullable|string|in:seller_exited',
            'deal_tag' => 'nullable|string|max:20000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $messageType = $request->input('message_type');
        $lot->load(['cryptoCurrency', 'fromCryptoCurrency', 'toCryptoCurrency']);
        $currency = self::sellerMailboxCryptoCode($lot);

        Log::info('[exchange-lots] sendMessageToBuyer: start', [
            'lot_id' => $lot->id,
            'lot_currency' => $currency,
            'lot_wallet_address_len' => strlen($lot->wallet_address ?? ''),
            'lot_wallet_address_preview' => $lot->wallet_address ? substr($lot->wallet_address, 0, 120).(strlen($lot->wallet_address) > 120 ? '...' : '') : null,
        ]);

        $sellerEmail = $this->lotMailboxService->getMailboxEmailForAddress($lot->wallet_address ?? '', $currency);
        if (! $sellerEmail && ! empty($lot->seller_lot_email)) {
            $sellerEmail = (string) $lot->seller_lot_email;
        }

        if (! $sellerEmail) {
            Log::warning('[exchange-lots] sendMessageToBuyer: no_seller_mailbox', ['lot_id' => $lot->id]);

            return response()->json([
                'success' => false,
                'message' => 'No seller mailbox for this lot.',
                'error_code' => 'no_seller_mailbox',
            ], 400);
        }

        $buyerEmail = $lot->buyer_lot_email;
        // When seller exits, we still delete reservation and clear lot even if buyer already left (buyer_lot_email empty).
        if (empty($buyerEmail) && $messageType !== 'seller_exited') {
            return response()->json([
                'success' => false,
                'message' => 'No buyer in deal for this lot.',
                'error_code' => 'no_buyer_in_deal',
            ], 400);
        }

        // For seller_exited we enforce transaction-level timeout windows from lot settings.
        // If at least one pending transaction is still inside its own timeout window,
        // neither side can break the deal yet.
        if ($messageType === 'seller_exited') {
            $lock = $this->getActiveDealExitLock($lot);
            if ($lock !== null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Deal cannot be exited yet. Transaction timeout has not passed.',
                    'error_code' => 'deal_exit_locked_by_transaction_timeout',
                    'locked_transaction_id' => $lock['transaction_id'],
                    'remaining_seconds' => $lock['remaining_seconds'],
                    'release_at' => $lock['release_at'],
                ], 403);
            }
            $disputeLock = $this->getActiveDisputeExitLock($lot);
            if ($disputeLock !== null) {
                return response()->json([
                    'success' => false,
                    'message' => 'A dispute is open. Deal exit is temporarily locked for compensation review.',
                    'error_code' => 'deal_exit_blocked_active_dispute_window',
                    'remaining_seconds' => $disputeLock['remaining_seconds'],
                    'release_at' => $disputeLock['release_at'],
                ], 403);
            }
            $exitPermission = $this->getDealExitPermissionForTimeout($lot);
            if ($exitPermission !== null && $exitPermission['allowed_side'] !== 'seller') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only the side that provided payment details can exit the deal after timeout.',
                    'error_code' => 'deal_exit_not_allowed_for_side',
                    'allowed_side' => $exitPermission['allowed_side'],
                    'transaction_id' => $exitPermission['transaction_id'],
                ], 403);
            }
        }

        $messageText = $request->input('message', '');
        $subject = $this->getDealMessageSubject($lot);
        $body = $messageText;

        // seller_exited: delete reservation in Core (release reserved amount), then clear buyer_lot_email
        if ($messageType === 'seller_exited') {
            $sellerEscrowToken = $request->input('escrow_token') ?? $request->header('X-Escrow-Token');
            if (! empty($sellerEscrowToken) && is_string($sellerEscrowToken)) {
                try {
                    [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($sellerEscrowToken);
                    try {
                        $cryptoType = self::coreCryptoTypeFromEscrowTokenJson($sellerEscrowToken)
                            ?? self::coreCryptoTypeForSellerEscrow($lot);
                    } catch (\InvalidArgumentException) {
                        $cryptoType = strtolower((string) ($lot->cryptoCurrency?->code ?? 'xmr'));
                    }
                    $timeout = (int) config('services.core.timeout', 10);
                    $req = \Illuminate\Support\Facades\Http::timeout($timeout);
                    if ($coreKey !== '') {
                        $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
                    }
                    $resp = $req->post(rtrim($coreUrl, '/').'/api/delete-reservation', [
                        'exchange_lot_id' => (string) $lot->id,
                        'escrow_token' => $sellerEscrowToken,
                        'crypto_type' => $cryptoType,
                    ]);
                    if ($resp->successful()) {
                        Log::info('[exchange-lots] sendMessageToBuyer: Core delete-reservation OK (seller_exited)', ['lot_id' => $lot->id]);
                    } else {
                        Log::warning('[exchange-lots] sendMessageToBuyer: Core delete-reservation failed (seller_exited)', [
                            'lot_id' => $lot->id,
                            'status' => $resp->status(),
                            'body' => $resp->body(),
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('[exchange-lots] sendMessageToBuyer: Core delete-reservation exception (seller_exited)', [
                        'lot_id' => $lot->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            DB::table('exchange_lots')
                ->where('id', $lot->id)
                ->update([
                    'buyer_lot_email' => null,
                    'buyer_seen_ip' => null,
                    'buyer_ip_share_with_seller' => false,
                ]);
            $this->closeOpenDisputesForLot(
                lot: $lot,
                resolvedBy: 'system',
                resolution: 'Deal was exited by seller.'
            );
            Log::info('[exchange-lots] sendMessageToBuyer: buyer_lot_email cleared (seller_exited)', ['lot_id' => $lot->id]);
        }

        $tagJson = $messageType === 'seller_exited'
            ? json_encode(['t' => 'seller_exited', 'lot_id' => $lot->id])
            : null;
        $customDealTagData = null;
        $customDealTagRaw = $request->input('deal_tag');
        if (is_string($customDealTagRaw) && trim($customDealTagRaw) !== '') {
            $customDealTagData = $this->decodeDealTagFromRaw($customDealTagRaw);
            if ($customDealTagData === null) {
                Log::warning('[exchange-lots] sendMessageToBuyer: invalid custom deal_tag JSON', [
                    'lot_id' => $lot->id,
                    'deal_tag_preview' => substr($customDealTagRaw, 0, 300),
                ]);
            } else {
                $receiptLinkReplacements = [];
                if (! array_key_exists('lot_id', $customDealTagData)) {
                    $customDealTagData['lot_id'] = (int) $lot->id;
                }
                $customDealTagData = $this->sanitizeIncomingCustomDealTag($customDealTagData);
                $customDealTagData = $this->promoteReceiptLinksInDealTag($customDealTagData, $receiptLinkReplacements);
                if (count($receiptLinkReplacements) > 0) {
                    $body = strtr($body, $receiptLinkReplacements);
                }
                $lot->refresh();
                $seqErr = DealChatTagSequenceValidator::validateSellerCustomDealTag($lot, $customDealTagData);
                if ($seqErr !== null) {
                    return response()->json([
                        'success' => false,
                        'message' => $seqErr['message'],
                        'error_code' => $seqErr['error_code'],
                    ], 422);
                }
                $coreTxCheck = $this->verifyCryptoTxDealTagViaCore($lot, $customDealTagData);
                if (($coreTxCheck['error'] ?? null) !== null) {
                    /** @var array{message:string,error_code:string,status?:int,verification?:array<string,mixed>} $err */
                    $err = $coreTxCheck['error'];
                    return response()->json([
                        'success' => false,
                        'message' => $err['message'],
                        'error_code' => $err['error_code'],
                        'verification' => $err['verification'] ?? null,
                    ], (int) ($err['status'] ?? 422));
                }
                $customDealTagData = $coreTxCheck['deal_tag'] ?? $customDealTagData;
                $receiptReuseCheck = $this->validateAndAttachReceiptChecksums(
                    lot: $lot,
                    senderSide: 'seller',
                    dealTagData: $customDealTagData
                );
                if (($receiptReuseCheck['error'] ?? null) !== null) {
                    /** @var array{message:string,error_code:string,status?:int} $err */
                    $err = $receiptReuseCheck['error'];
                    return response()->json([
                        'success' => false,
                        'message' => $err['message'],
                        'error_code' => $err['error_code'],
                    ], (int) ($err['status'] ?? 422));
                }
                $customDealTagData = $receiptReuseCheck['deal_tag'] ?? $customDealTagData;
                $disputeOpenCheck = $this->validateDisputeOpenGuard(
                    lot: $lot,
                    senderSide: 'seller',
                    dealTagData: $customDealTagData
                );
                if ($disputeOpenCheck !== null) {
                    return response()->json([
                        'success' => false,
                        'message' => $disputeOpenCheck['message'],
                        'error_code' => $disputeOpenCheck['error_code'],
                    ], (int) ($disputeOpenCheck['status'] ?? 422));
                }
                if (($customDealTagData['t'] ?? '') === \App\Support\SellerStageUndoSupport::TAG) {
                    $sellerEscrowForUndo = $request->input('escrow_token') ?? $request->header('X-Escrow-Token');
                    \App\Support\SellerStageUndoSupport::applyDatabaseSideEffects(
                        $lot,
                        trim((string) ($customDealTagData['step'] ?? '')),
                        is_string($sellerEscrowForUndo) ? $sellerEscrowForUndo : null,
                        fn (string $token) => $this->escrowTokenValidation->getCoreUrlAndKeyForToken($token),
                    );
                }
                $tagJson = json_encode($customDealTagData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        if ($messageType === 'seller_exited') {
            $customDealTagData = null;
            $tagJson = json_encode(['t' => 'seller_exited', 'lot_id' => $lot->id]);
        }

        if (is_array($customDealTagData) && ($customDealTagData['t'] ?? '') === 'deal_completed_seller_timeout') {
            $denySellerTimeout = $this->denySellerTimeoutDealCompletionUnlessAllowed($lot, $customDealTagData);
            if ($denySellerTimeout !== null) {
                return $denySellerTimeout;
            }
        }

        if (! empty($buyerEmail)) {
            Log::info('[exchange-lots] sendMessageToBuyer: sending mail', [
                'lot_id' => $lot->id,
                'to' => $buyerEmail,
                'subject' => $subject,
            ]);

            try {
                $this->sendDealEmail(
                    to: $buyerEmail,
                    subject: $subject,
                    fromAddress: config('mail.from.address', 'noreply@xdexboard.com'),
                    fromName: config('mail.from.name', 'XBoard'),
                    body: $body,
                    tagJson: $tagJson
                );
                $this->storeReceiptLinksInLatestTransaction($lot, $customDealTagData);
            } catch (\Throwable $e) {
                Log::warning('[exchange-lots] sendMessageToBuyer: failed to send mail', [
                    'lot_id' => $lot->id,
                    'to' => $buyerEmail,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
                Log::debug('[exchange-lots] sendMessageToBuyer: exception trace', [
                    'trace' => $e->getTraceAsString(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send message: '.$e->getMessage(),
                ], 500);
            }

            $chatPersisted = $this->insertDealChatMessage(
                $lot,
                'seller',
                $sellerEmail,
                $buyerEmail,
                $body,
                $tagJson
            );
            $this->syncDisputeStateFromDealTag(
                lot: $lot,
                senderSide: 'seller',
                dealTagData: $customDealTagData,
                messageType: $messageType
            );
            $completionError = $this->finalizeAmicableDealCompletionIfNeeded($lot, $request, $customDealTagData, $chatPersisted);
            if ($completionError !== null) {
                return $completionError;
            }

            Log::info('[exchange-lots] sendMessageToBuyer: mail sent', [
                'lot_id' => $lot->id,
                'to' => $buyerEmail,
            ]);
        } elseif ($messageType === 'seller_exited' && is_string($tagJson) && trim($tagJson) !== '') {
            // If the buyer mailbox is already cleared (buyer left first), we still need a durable chat tag
            // so downstream flows (purge, UI restore) can observe a terminal state.
            $decodedExitTag = json_decode($tagJson, true);
            $exitTagData = is_array($decodedExitTag) ? $decodedExitTag : null;
            $toEmailForChat = trim((string) ($buyerEmail ?? ''));
            if ($toEmailForChat === '') {
                $toEmailForChat = config('mail.from.address', 'noreply@xdexboard.com');
            }
            try {
                $this->insertDealChatMessage(
                    $lot,
                    'seller',
                    $sellerEmail,
                    $toEmailForChat,
                    $body,
                    $tagJson
                );
                $this->syncDisputeStateFromDealTag(
                    lot: $lot,
                    senderSide: 'seller',
                    dealTagData: $exitTagData,
                    messageType: $messageType
                );
                Log::info('[exchange-lots] sendMessageToBuyer: seller_exited chat message inserted without buyer mailbox', [
                    'lot_id' => $lot->id,
                    'to_email_for_chat' => $toEmailForChat,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[exchange-lots] sendMessageToBuyer: seller_exited chat insert failed', [
                    'lot_id' => $lot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Message sent to buyer.',
        ]);
    }

    /**
     * Seller posts deal_completed_seller_timeout only after the buyer’s completion window expired (mirrors client step timer).
     *
     * @param  array<string, mixed>  $dealTagData
     */
    private function denySellerTimeoutDealCompletionUnlessAllowed(ExchangeLot $lot, array $dealTagData): ?JsonResponse
    {
        if (($dealTagData['t'] ?? '') !== 'deal_completed_seller_timeout') {
            return null;
        }
        $tagLot = (string) ($dealTagData['lot_id'] ?? '');
        if ($tagLot === '' || $tagLot !== (string) $lot->id) {
            return response()->json([
                'success' => false,
                'message' => 'deal_tag lot_id does not match this lot.',
                'error_code' => 'deal_tag_lot_mismatch',
            ], 422);
        }
        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
        if ($buyerEmail === '') {
            return response()->json([
                'success' => false,
                'message' => 'No buyer in deal for this lot.',
                'error_code' => 'no_buyer_in_deal',
            ], 400);
        }
        $pendingExists = Transaction::query()
            ->where('exchange_lot_id', $lot->id)
            ->where('buyer_lot_email', $buyerEmail)
            ->where('tx_type', 'sale')
            ->where('status', 'pending')
            ->exists();
        if (! $pendingExists) {
            $completedExists = Transaction::query()
                ->where('exchange_lot_id', $lot->id)
                ->where('buyer_lot_email', $buyerEmail)
                ->where('tx_type', 'sale')
                ->where('status', 'completed')
                ->exists();
            if ($completedExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'This deal is already marked completed on the server.',
                    'error_code' => 'seller_completion_sale_already_completed',
                ], 403);
            }

            return response()->json([
                'success' => false,
                'message' => 'No pending sale transaction to complete.',
                'error_code' => 'seller_completion_no_pending_sale',
            ], 403);
        }
        if ($this->lotHasActiveDispute($lot)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot confirm deal completion this way while a dispute is open.',
                'error_code' => 'seller_completion_blocked_active_dispute',
            ], 403);
        }
        $messages = DealChatMessage::query()
            ->where('exchange_lot_id', $lot->id)
            ->orderBy('id')
            ->get(['id', 'deal_tag', 'created_at']);
        $lastTimeoutMinutesFromSellerFunds = null;
        $anchor = null;
        $buyerReceivesCrypto = $lot->to_crypto_currency_id !== null;
        foreach ($messages as $row) {
            $raw = $row->deal_tag;
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            $d = json_decode($raw, true);
            if (! is_array($d)) {
                continue;
            }
            $t = isset($d['t']) ? trim((string) $d['t']) : '';
            if ($t === 'deal_completed_buyer') {
                return response()->json([
                    'success' => false,
                    'message' => 'Buyer already confirmed deal completion.',
                    'error_code' => 'seller_completion_buyer_already_completed',
                ], 403);
            }
            if ($t === 'deal_completed_seller_timeout') {
                return response()->json([
                    'success' => false,
                    'message' => 'Seller already posted timeout completion for this deal.',
                    'error_code' => 'seller_completion_duplicate',
                ], 409);
            }
            if ($t === 'deal_completed_seller' && ! empty($d['crypto_payout_tx'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Deal already completed via seller crypto payout.',
                    'error_code' => 'seller_completion_already_finalized',
                ], 403);
            }
            if ($t === 'seller_received_funds') {
                $lastTimeoutMinutesFromSellerFunds = isset($d['timeout_minutes']) ? (int) $d['timeout_minutes'] : null;
                if (! $buyerReceivesCrypto) {
                    $anchor = $row->created_at;
                }
            }
            if ($buyerReceivesCrypto && $t === 'seller_transfer_tx') {
                $anchor = $row->created_at;
            }
        }
        if ($anchor === null) {
            return response()->json([
                'success' => false,
                'message' => $buyerReceivesCrypto
                    ? 'Confirm completion only after you sent transfer proof to the buyer (seller_transfer_tx).'
                    : 'Confirm completion only after you confirmed receiving funds (seller_received_funds).',
                'error_code' => 'seller_completion_wrong_state',
            ], 403);
        }
        $transaction = Transaction::query()
            ->where('exchange_lot_id', $lot->id)
            ->where('buyer_lot_email', $buyerEmail)
            ->where('tx_type', 'sale')
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->first();
        $timeoutMinutes = (int) ($transaction?->transaction_timeout_minutes ?? 0);
        if ($timeoutMinutes <= 0) {
            $timeoutMinutes = (int) ($lastTimeoutMinutesFromSellerFunds ?? \App\Support\BuyerPaymentTimeoutMinutes::effectiveForLot(
                isset($lot->buyer_payment_timeout_minutes) ? (int) $lot->buyer_payment_timeout_minutes : null
            ));
        }
        if ($timeoutMinutes <= 0) {
            $timeoutMinutes = 30;
        }
        $deadline = $anchor->copy()->addMinutes($timeoutMinutes);
        if ($deadline->isFuture()) {
            $remaining = (int) now()->diffInSeconds($deadline);

            return response()->json([
                'success' => false,
                'message' => 'Buyer’s time to confirm completion has not expired yet.',
                'error_code' => 'seller_completion_timer_active',
                'remaining_seconds' => $remaining,
                'release_at' => $this->toUtcIso8601($deadline),
            ], 403);
        }

        return null;
    }

    /**
     * Buyer cancels the deal: deletes transaction(s) and reservation(s) in Core, clears buyer_lot_email, notifies seller.
     * Only the buyer (escrow token hash matches lot.buyer_escrow_token_hash) can call this.
     *
     * Unlike {@see sendMessageToBuyer} with seller_exited, buyer cancel is not blocked by the pending sale
     * transaction timeout window nor by payment_details_set_by — the buyer may leave while payment details
     * are shown; the seller remains subject to timeout / side rules on seller exit.
     * Buyer cancel is blocked while an {@see DealDispute} is opened or in_review (same as seller exit).
     */
    public function buyerCancelDeal(Request $request, ExchangeLot $lot): JsonResponse
    {
        $escrowToken = $request->input('escrow_token') ?? $request->header('X-Escrow-Token');
        Log::info('[exchange-lots] buyerCancelDeal: request', [
            'lot_id' => $lot->id,
            'has_escrow_token' => ! empty($escrowToken) && is_string($escrowToken),
            'token_from' => $request->has('escrow_token') ? 'body' : ($request->header('X-Escrow-Token') ? 'header' : 'none'),
            'buyer_escrow_token_hash_set' => ! empty($lot->buyer_escrow_token_hash),
        ]);

        if (empty($escrowToken) || ! is_string($escrowToken)) {
            Log::warning('[exchange-lots] buyerCancelDeal: 422 escrow_token missing', ['lot_id' => $lot->id]);

            return response()->json([
                'success' => false,
                'message' => 'escrow_token is required (buyer\'s full token).',
                'error_code' => 'validation_error',
            ], 422);
        }

        if (empty($lot->buyer_escrow_token_hash)) {
            Log::warning('[exchange-lots] buyerCancelDeal: 404 no buyer linked', ['lot_id' => $lot->id]);

            return response()->json([
                'success' => false,
                'message' => 'This lot has no buyer linked for cancellation.',
                'error_code' => 'no_buyer',
            ], 404);
        }

        // Same as start_deal: we store token_hash from the public part JSON. Compare using that field, not sha256(body).
        $decoded = json_decode($escrowToken, true);
        $requestorHash = null;
        if (is_array($decoded) && isset($decoded['token_hash']) && is_string($decoded['token_hash'])) {
            $requestorHash = strtolower(trim($decoded['token_hash']));
        }
        if ($requestorHash === null || strlen($requestorHash) > 64) {
            $requestorHash = hash('sha256', $escrowToken);
        }
        $storedHash = strtolower(trim($lot->buyer_escrow_token_hash));
        if (! hash_equals($storedHash, $requestorHash)) {
            Log::warning('[exchange-lots] buyerCancelDeal: 403 token hash mismatch', [
                'lot_id' => $lot->id,
                'stored_hash_len' => strlen($storedHash),
                'requestor_hash_len' => strlen($requestorHash),
                'stored_hash_prefix' => substr($storedHash, 0, 8),
                'requestor_hash_prefix' => substr($requestorHash, 0, 8),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Only the buyer can cancel this deal.',
                'error_code' => 'forbidden',
            ], 403);
        }

        $disputeLock = $this->getActiveDisputeExitLock($lot);
        if ($disputeLock !== null) {
            return response()->json([
                'success' => false,
                'message' => 'A dispute is open. Deal cancellation is temporarily locked for compensation review.',
                'error_code' => 'buyer_cancel_blocked_active_dispute_window',
                'remaining_seconds' => $disputeLock['remaining_seconds'],
                'release_at' => $disputeLock['release_at'],
            ], 403);
        }

        // After completion + purge (or other cleanup): buyer_lot_email may already be null while the token hash
        // is retained for chat auth. If there is no pending sale left, treat cancel as a no-op success so the client can leave.
        $buyerEmailForCancel = trim((string) ($lot->buyer_lot_email ?? ''));
        if ($buyerEmailForCancel === '') {
            $pendingSale = Transaction::query()
                ->where('exchange_lot_id', $lot->id)
                ->where('tx_type', 'sale')
                ->where('status', 'pending')
                ->exists();
            if (! $pendingSale) {
                Log::info('[exchange-lots] buyerCancelDeal: already cleared (no buyer email, no pending sale)', ['lot_id' => $lot->id]);

                return response()->json([
                    'success' => true,
                    'message' => 'Deal already cleared on the server; nothing to cancel.',
                    'already_cleared' => true,
                ]);
            }
        }

        $transactions = Transaction::where('exchange_lot_id', $lot->id)->where('buyer_lot_email', $lot->buyer_lot_email)->get();
        [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($escrowToken);
        $timeout = (int) config('services.core.timeout', 10);
        try {
            $cryptoType = self::coreCryptoTypeFromEscrowTokenJson($escrowToken)
                ?? self::coreCryptoTypeForBuyerEscrow($lot);
        } catch (\InvalidArgumentException) {
            $cryptoType = strtolower((string) ($lot->cryptoCurrency?->code ?? 'xmr'));
        }

        // Delete reservation in Core (works with or without transactions: by exchange_lot_id + buyer token)
        try {
            $req = Http::timeout($timeout);
            if ($coreKey !== '') {
                $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
            }
            $resp = $req->post(rtrim($coreUrl, '/').'/api/delete-reservation-by-buyer', [
                'exchange_lot_id' => (string) $lot->id,
                'escrow_token' => $escrowToken,
                'crypto_type' => $cryptoType,
            ]);
            if ($resp->successful()) {
                Log::info('[exchange-lots] buyerCancelDeal: Core delete-reservation-by-buyer OK', ['lot_id' => $lot->id]);
            } else {
                Log::warning('[exchange-lots] buyerCancelDeal: Core delete-reservation-by-buyer failed', [
                    'lot_id' => $lot->id,
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                    'core_url' => rtrim($coreUrl, '/').'/api/delete-reservation-by-buyer',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] buyerCancelDeal: Core delete-reservation-by-buyer exception', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
            ]);
        }

        Transaction::where('exchange_lot_id', $lot->id)->where('buyer_lot_email', $lot->buyer_lot_email)->delete();
        DB::table('exchange_lots')->where('id', $lot->id)->update([
            'buyer_lot_email' => null,
            'buyer_seen_ip' => null,
            'buyer_ip_share_with_seller' => false,
        ]);
        $this->closeOpenDisputesForLot(
            lot: $lot,
            resolvedBy: 'system',
            resolution: 'Deal was cancelled by buyer.'
        );

        $lot->load(['cryptoCurrency', 'fromCryptoCurrency', 'toCryptoCurrency']);
        $sellerEmail = $this->lotMailboxService->getMailboxEmailForAddress($lot->wallet_address ?? '', self::sellerMailboxCryptoCode($lot));
        if (! $sellerEmail && ! empty($lot->seller_lot_email)) {
            $sellerEmail = (string) $lot->seller_lot_email;
        }
        if ($sellerEmail) {
            try {
                $tagJson = json_encode(['t' => 'buyer_exited', 'lot_id' => $lot->id]);
                // No localization on server: body from client. If client sent empty, leave empty (no space in chat/DB).
                $buyerExitedBody = is_string($request->input('message')) ? trim($request->input('message')) : '';
                if ($buyerExitedBody === '') {
                    Log::warning('[exchange-lots] buyerCancelDeal: client sent empty message', ['lot_id' => $lot->id]);
                }
                $buyerExitedSubject = is_string($request->input('subject')) ? trim($request->input('subject')) : '';
                Mail::raw(
                    $buyerExitedBody,
                    function ($message) use ($sellerEmail, $tagJson, $buyerExitedSubject, $buyerExitedBody) {
                        $message->to($sellerEmail);
                        $message->subject($buyerExitedSubject !== '' ? $buyerExitedSubject : $buyerExitedBody);
                        $message->from(config('mail.from.address', 'noreply@xdexboard.com'), config('mail.from.name', 'XBoard'));
                        $message->getHeaders()->addTextHeader('X-XBoard-Deal-Tag', $tagJson);
                        HighDeliveryPriority::apply($message);
                    }
                );
                $this->insertDealChatMessage(
                    $lot,
                    'buyer',
                    config('mail.from.address'),
                    $sellerEmail,
                    $buyerExitedBody,
                    $tagJson
                );
            } catch (\Throwable $e) {
                Log::warning('[exchange-lots] buyerCancelDeal: failed to notify seller', ['lot_id' => $lot->id, 'error' => $e->getMessage()]);
            }
        }

        Log::info('[exchange-lots] buyerCancelDeal: deal cancelled', ['lot_id' => $lot->id]);

        return response()->json([
            'success' => true,
            'message' => 'Deal cancelled. Transactions and reservations have been removed.',
        ]);
    }

    /**
     * Backward-compatible buyer cancel endpoint for clients that always send lot_id in URL.
     * If lot is already removed, fallback to token-based forced cleanup.
     */
    public function buyerCancelDealCompat(Request $request, int $lotId): JsonResponse
    {
        $lot = ExchangeLot::query()->find($lotId);
        if ($lot === null) {
            Log::warning('[exchange-lots] buyerCancelDealCompat: lot not found, fallback by token', [
                'lot_id' => $lotId,
            ]);

            return $this->buyerCancelDealByToken($request);
        }

        return $this->buyerCancelDeal($request, $lot);
    }

    /**
     * Buyer cancel fallback without lot_id.
     * Useful when client holds a stale/removed lot reference but still needs to leave current deal state.
     */
    public function buyerCancelDealByToken(Request $request): JsonResponse
    {
        $tokenData = $request->input('escrow_token_data', []);
        $requestTokenHash = is_array($tokenData) ? strtolower(trim((string) ($tokenData['token_hash'] ?? ''))) : '';
        if ($requestTokenHash === '' || strlen($requestTokenHash) > 64) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid escrow token data.',
                'error_code' => 'invalid_token_data',
            ], 401);
        }

        $lots = ExchangeLot::query()
            ->whereNotNull('buyer_escrow_token_hash')
            ->whereRaw('LOWER(TRIM(buyer_escrow_token_hash)) = ?', [$requestTokenHash])
            ->orderByDesc('id')
            ->get();

        if ($lots->isEmpty()) {
            Log::info('[exchange-lots] buyerCancelDealByToken: no linked lot found, already cleared', [
                'token_hash_prefix' => substr($requestTokenHash, 0, 8),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Deal already cleared on the server; nothing to cancel.',
                'already_cleared' => true,
            ]);
        }

        $escrowToken = $request->input('escrow_token') ?? $request->header('X-Escrow-Token');
        if (! is_string($escrowToken) || trim($escrowToken) === '') {
            return response()->json([
                'success' => false,
                'message' => 'escrow_token is required (buyer\'s full token).',
                'error_code' => 'validation_error',
            ], 422);
        }
        [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($escrowToken);
        $timeout = (int) config('services.core.timeout', 10);
        $releasedLotIds = [];
        $deletedTransactions = 0;
        $clearedLots = 0;

        foreach ($lots as $lot) {
            /** @var ExchangeLot $lot */
            try {
                $cryptoType = self::coreCryptoTypeFromEscrowTokenJson($escrowToken)
                    ?? self::coreCryptoTypeForBuyerEscrow($lot);
            } catch (\Throwable) {
                $cryptoType = strtolower((string) ($lot->cryptoCurrency?->code ?? 'xmr'));
            }

            // Best-effort: release reservation in Core. Even if it fails, proceed with server-side cleanup.
            try {
                $req = Http::timeout($timeout);
                if ($coreKey !== '') {
                    $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
                }
                $resp = $req->post(rtrim($coreUrl, '/').'/api/delete-reservation-by-buyer', [
                    'exchange_lot_id' => (string) $lot->id,
                    'escrow_token' => $escrowToken,
                    'crypto_type' => $cryptoType,
                ]);
                if ($resp->successful()) {
                    $releasedLotIds[] = (int) $lot->id;
                } else {
                    Log::warning('[exchange-lots] buyerCancelDealByToken: Core delete-reservation-by-buyer failed', [
                        'lot_id' => $lot->id,
                        'status' => $resp->status(),
                        'body' => $resp->body(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('[exchange-lots] buyerCancelDealByToken: Core delete-reservation-by-buyer exception', [
                    'lot_id' => $lot->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
            if ($buyerEmail !== '') {
                $deletedTransactions += (int) Transaction::query()
                    ->where('exchange_lot_id', $lot->id)
                    ->where('buyer_lot_email', $buyerEmail)
                    ->delete();
            } else {
                $deletedTransactions += (int) Transaction::query()
                    ->where('exchange_lot_id', $lot->id)
                    ->where('tx_type', 'sale')
                    ->where('status', 'pending')
                    ->delete();
            }

            $updated = ExchangeLot::query()
                ->where('id', $lot->id)
                ->update([
                    'buyer_lot_email' => null,
                    'buyer_selected_amount' => null,
                    'buyer_escrow_token_hash' => null,
                    'buyer_seen_ip' => null,
                    'buyer_ip_share_with_seller' => false,
                ]);
            if ($updated > 0) {
                $clearedLots += 1;
            }
        }

        Log::info('[exchange-lots] buyerCancelDealByToken: forced buyer cleanup completed', [
            'token_hash_prefix' => substr($requestTokenHash, 0, 8),
            'lots_found' => $lots->count(),
            'lots_cleared' => $clearedLots,
            'transactions_deleted' => $deletedTransactions,
            'core_released_lot_ids' => $releasedLotIds,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Buyer deal state cleared by token.',
            'lots_found' => $lots->count(),
            'lots_cleared' => $clearedLots,
            'transactions_deleted' => $deletedTransactions,
            'core_released_lot_ids' => $releasedLotIds,
        ]);
    }

    /**
     * Buyer provides destination details for the seller transfer on the second leg of a deal.
     * Symmetric to {@see self::sendPaymentDetails()} but from the buyer side: depending on
     * the currency the buyer expects to receive, details can be either a crypto address
     * (payment_type=crypto) or free-form bank requisites (payment_type=bank, bank_data).
     *
     * The call updates the latest pending sale transaction for this buyer + lot by setting
     * `transactions.crypto_address` (crypto) or `transactions.bank_details` (bank).
     */
    public function buyerSetCryptoDestination(Request $request, ExchangeLot $lot): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_type' => 'nullable|string|in:crypto,bank',
            'address' => 'nullable|string|max:500',
            'crypto_type' => 'nullable|string|max:30',
            'bank_data' => 'nullable|string|max:5000',
            'message' => 'nullable|string|max:10000',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Backward compatibility: older clients sent only `address` (crypto flow).
        $paymentType = $request->input('payment_type');
        if ($paymentType === null || $paymentType === '') {
            $paymentType = $request->filled('bank_data') ? 'bank' : 'crypto';
        }

        $address = trim((string) $request->input('address', ''));
        $bankData = trim((string) $request->input('bank_data', ''));
        $cryptoType = trim((string) $request->input('crypto_type', ''));

        if ($paymentType === 'crypto' && $address === '') {
            return response()->json([
                'success' => false,
                'message' => 'Crypto destination address must not be empty.',
                'error_code' => 'validation_error',
            ], 422);
        }
        if ($paymentType === 'bank' && $bankData === '') {
            return response()->json([
                'success' => false,
                'message' => 'Bank transfer details must not be empty.',
                'error_code' => 'validation_error',
            ], 422);
        }

        if ($paymentType === 'crypto') {
            try {
                $lot->loadMissing(['fromCryptoCurrency', 'toCryptoCurrency', 'cryptoCurrency']);
                $payoutCryptoType = self::coreCryptoTypeForSellerEscrow($lot);
                $addrError = self::validateStablecoinPayoutDestinationAddress($address, $payoutCryptoType);
                if ($addrError !== null) {
                    return response()->json([
                        'success' => false,
                        'message' => $addrError['message'],
                        'error_code' => $addrError['error_code'],
                    ], 422);
                }
            } catch (\InvalidArgumentException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
        }

        $buyerEmail = (string) ($lot->buyer_lot_email ?? '');
        if ($buyerEmail === '') {
            return response()->json([
                'success' => false,
                'message' => 'This lot has no buyer linked.',
                'error_code' => 'no_buyer',
            ], 404);
        }

        $buyerEscrowTokenHash = $lot->buyer_escrow_token_hash;
        if (empty($buyerEscrowTokenHash)) {
            return response()->json([
                'success' => false,
                'message' => 'Buyer escrow token hash is not linked for this lot.',
                'error_code' => 'buyer_token_not_linked',
            ], 403);
        }

        // Validate token hash against the linked buyer escrow token hash.
        // ValidateEscrowToken middleware attaches parsed data in escrow_token_data.
        $tokenData = $request->input('escrow_token_data', []);
        $requestorTokenHash = is_array($tokenData) ? ($tokenData['token_hash'] ?? null) : null;
        if (empty($requestorTokenHash) || ! is_string($requestorTokenHash)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid escrow token data.',
                'error_code' => 'invalid_token_data',
            ], 401);
        }

        $storedHash = strtolower(trim((string) $buyerEscrowTokenHash));
        $requestHash = strtolower(trim((string) $requestorTokenHash));
        if (! hash_equals($storedHash, $requestHash)) {
            return response()->json([
                'success' => false,
                'message' => 'Only the buyer can set crypto destination for this deal.',
                'error_code' => 'forbidden',
            ], 403);
        }

        // Update the latest pending sale transaction for this buyer + lot.
        $transaction = Transaction::query()
            ->where('exchange_lot_id', $lot->id)
            ->where('buyer_lot_email', $buyerEmail)
            ->where('tx_type', 'sale')
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->first();

        if ($transaction === null) {
            // Fallback: try any latest sale transaction in case the backend changed `status` briefly.
            $transaction = Transaction::query()
                ->where('exchange_lot_id', $lot->id)
                ->where('buyer_lot_email', $buyerEmail)
                ->where('tx_type', 'sale')
                ->orderByDesc('id')
                ->first();

            if ($transaction === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'No sale transaction found for this deal.',
                    'error_code' => 'no_sale_transaction',
                ], 404);
            }
        }

        if ($paymentType === 'crypto') {
            $transaction->crypto_address = $address;
            $transaction->bank_details = null;
        } else {
            $transaction->bank_details = $bankData;
            $transaction->crypto_address = null;
        }
        // Buyer provided destination details for transfer in this deal stage.
        $transaction->payment_details_set_by = 'buyer';
        $transaction->save();

        $lot->load(['cryptoCurrency', 'fromCryptoCurrency', 'toCryptoCurrency']);
        $sellerEmail = $this->lotMailboxService->getMailboxEmailForAddress($lot->wallet_address ?? '', self::sellerMailboxCryptoCode($lot));
        if (! $sellerEmail && ! empty($lot->seller_lot_email)) {
            $sellerEmail = (string) $lot->seller_lot_email;
        }

        $tag = [
            't' => 'buyer_destination_details',
            'lot_id' => (int) $lot->id,
            'tx_id' => (int) $transaction->id,
        ];
        if ($paymentType === 'crypto') {
            if ($cryptoType !== '') {
                $tag['crypto'] = $cryptoType;
            }
            $tag['address'] = $address;
        } else {
            $tag['bank_data'] = $bankData;
        }
        $tagJson = json_encode($tag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Body = what the buyer wrote (shown in chat). Technical data is in X-XBoard-Deal-Tag.
        $buyerMessage = trim((string) $request->input('message', ''));
        $body = $buyerMessage !== ''
            ? $buyerMessage
            : ($paymentType === 'bank' ? $bankData : $address);

        if (! empty($sellerEmail)) {
            try {
                $this->sendDealEmail(
                    to: $sellerEmail,
                    subject: $this->getDealMessageSubject($lot),
                    fromAddress: config('mail.from.address', 'noreply@xdexboard.com'),
                    fromName: config('mail.from.name', 'XBoard'),
                    body: $body,
                    tagJson: $tagJson
                );
            } catch (\Throwable $e) {
                Log::warning('[exchange-lots] buyerSetCryptoDestination: failed to send seller mail', [
                    'lot_id' => $lot->id,
                    'to' => $sellerEmail,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->insertDealChatMessage(
            $lot,
            'buyer',
            config('mail.from.address', 'noreply@xdexboard.com'),
            $sellerEmail,
            $body,
            $tagJson
        );

        if ($paymentType === 'bank' && SandboxVirtualBankService::lotUsesVirtualSandboxBank($lot)) {
            $sandboxReceiptAmount = (float) ($transaction->amount ?? 0);
            if ($sandboxReceiptAmount <= 0) {
                $sandboxReceiptAmount = $lot->buyer_selected_amount > 0
                    ? (float) $lot->buyer_selected_amount
                    : (float) $lot->sell_amount;
            }
            $this->sendSandboxVirtualBankReceiptEmail($lot, $transaction, $sandboxReceiptAmount);
        }

        return response()->json([
            'success' => true,
            'message' => 'Crypto destination address saved.',
            'transaction_id' => $transaction->id,
        ]);
    }

    /**
     * Buyer: send crypto to the seller address stored on the pending sale transaction (seller-set crypto payment details).
     * For AML-token flow this replaces the manual buyer_crypto_tx message: server performs the transfer
     * and then publishes buyer_crypto_tx with the real blockchain tx id.
     */
    public function buyerCryptoPayoutToSeller(Request $request, ExchangeLot $lot): JsonResponse
    {
        if (! $this->requesterIsBuyerForLot($request, $lot)) {
            return response()->json(['success' => false, 'message' => 'Only the lot buyer can send crypto payout.'], 403);
        }

        if ($this->lotHasActiveDispute($lot)) {
            return response()->json([
                'success' => false,
                'message' => 'Crypto payout is not allowed while a dispute is open.',
                'error_code' => 'payout_blocked_active_dispute',
            ], 403);
        }

        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
        if ($buyerEmail === '') {
            return response()->json(['success' => false, 'message' => 'No buyer in deal.'], 400);
        }

        $buyerEscrowToken = $request->input('escrow_token') ?? $request->header('X-Escrow-Token');
        if (empty($buyerEscrowToken) || ! is_string($buyerEscrowToken)) {
            return response()->json([
                'success' => false,
                'message' => 'Full buyer escrow_token required (X-Escrow-Token or body).',
            ], 422);
        }

        return DB::transaction(function () use ($lot, $buyerEmail, $buyerEscrowToken): JsonResponse {
            $transaction = Transaction::query()
                ->where('exchange_lot_id', $lot->id)
                ->where('buyer_lot_email', $buyerEmail)
                ->where('tx_type', 'sale')
                ->where('status', 'pending')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($transaction === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'No pending sale transaction for this deal.',
                    'error_code' => 'no_pending_tx',
                ], 400);
            }

            if (! empty($transaction->buyer_crypto_payout_tx_hash)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Crypto payout already submitted for this transaction.',
                    'error_code' => 'payout_already_sent',
                    'transaction_id' => $transaction->id,
                    'crypto_tx_id' => $transaction->buyer_crypto_payout_tx_hash,
                ], 409);
            }

            $dest = trim((string) ($transaction->crypto_address ?? ''));
            if ($dest === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Seller has not set a crypto payment address for this deal.',
                    'error_code' => 'seller_crypto_address_missing',
                ], 400);
            }

            $amount = (float) $transaction->amount;
            if ($amount <= 0) {
                return response()->json(['success' => false, 'message' => 'Invalid transaction amount.'], 422);
            }

            try {
                $cryptoType = self::resolveBuyerDealPayoutCryptoType($lot, $buyerEscrowToken);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            $payoutNetwork = self::coreNetworkFromCryptoType($cryptoType);
            $destNet = self::inferStablecoinNetworkFromBlockchainAddress($dest);
            if ($payoutNetwork !== null && $destNet !== null && $payoutNetwork !== $destNet) {
                return response()->json([
                    'success' => false,
                    'message' => 'Seller payment address network does not match deal payment asset ('.$payoutNetwork.').',
                    'error_code' => 'seller_crypto_address_network_mismatch',
                ], 422);
            }

            [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($buyerEscrowToken);
            $timeout = (int) config('services.core.payout_timeout', 120);
            $destinationPreview = strlen($dest) > 24 ? (substr($dest, 0, 24).'...') : $dest;
            $payload = [
                'exchange_lot_id' => (string) $lot->id,
                'laravel_transaction_id' => $transaction->id,
                'escrow_token' => $buyerEscrowToken,
                'destination_address' => $dest,
                'amount' => $amount,
                'crypto_type' => $cryptoType,
                'native_amount' => 0.0,
                'aml_required' => (bool) $lot->aml,
            ];
            if ($payoutNetwork !== null) {
                $payload['network'] = $payoutNetwork;
            }

            $req = Http::timeout($timeout)->asJson();
            if ($coreKey !== '') {
                $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
            }
            $coreEndpoint = rtrim($coreUrl, '/').'/api/deal-payout-to-seller-address';
            Log::info('[exchange-lots] buyerCryptoPayoutToSeller: core payout start', [
                'lot_id' => $lot->id,
                'transaction_id' => $transaction->id,
                'core_endpoint' => $coreEndpoint,
                'timeout_seconds' => $timeout,
                'crypto_type' => $cryptoType,
                'amount' => $amount,
                'destination_preview' => $destinationPreview,
                'aml_required' => (bool) $lot->aml,
            ]);
            $startedAt = microtime(true);
            try {
                $resp = $req->post($coreEndpoint, $payload);
            } catch (\Throwable $e) {
                Log::warning('[exchange-lots] buyerCryptoPayoutToSeller: core payout exception', [
                    'lot_id' => $lot->id,
                    'transaction_id' => $transaction->id,
                    'core_endpoint' => $coreEndpoint,
                    'timeout_seconds' => $timeout,
                    'took_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'exception_class' => $e::class,
                    'exception' => $e->getMessage(),
                ]);
                throw $e;
            }
            $responseBody = $resp->json();
            $responseBodyPreview = is_array($responseBody)
                ? json_encode($responseBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $resp->body();
            if (is_string($responseBodyPreview) && strlen($responseBodyPreview) > 500) {
                $responseBodyPreview = substr($responseBodyPreview, 0, 500).'...';
            }
            Log::info('[exchange-lots] buyerCryptoPayoutToSeller: core payout response', [
                'lot_id' => $lot->id,
                'transaction_id' => $transaction->id,
                'took_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'core_http_status' => $resp->status(),
                'core_successful' => $resp->successful(),
                'core_transaction_id' => is_array($responseBody) ? ($responseBody['data']['transaction_id'] ?? null) : null,
                'core_idempotent_replay' => is_array($responseBody) ? ($responseBody['data']['idempotent_replay'] ?? null) : null,
                'core_body_preview' => $responseBodyPreview,
            ]);

            if (! $resp->successful()) {
                $body = $responseBody;
                $message = is_array($body) ? (string) ($body['message'] ?? 'Core payout failed') : $resp->body();
                $this->persistPayoutAmlAndAlertOperatorIfNeeded(
                    $lot,
                    $transaction,
                    'buyer_crypto_payout_to_seller',
                    'buyer',
                    $buyerEscrowToken,
                    $cryptoType,
                    $coreUrl,
                    $coreKey,
                    $resp->status(),
                    is_array($body) ? $body : ['raw' => (string) $resp->body()],
                );
                Log::warning('[exchange-lots] buyerCryptoPayoutToSeller: core payout non-success', [
                    'lot_id' => $lot->id,
                    'transaction_id' => $transaction->id,
                    'core_http_status' => $resp->status(),
                    'error_code' => is_array($body) ? ($body['error_code'] ?? 'core_payout_failed') : 'core_payout_failed',
                    'message' => $message,
                    'core_body_preview' => $responseBodyPreview,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'error_code' => is_array($body) ? ($body['error_code'] ?? 'core_payout_failed') : 'core_payout_failed',
                    'aml' => is_array($body) ? ($body['aml'] ?? null) : null,
                ], $resp->status() >= 500 ? 502 : 422);
            }

            $body = $responseBody;
            $cryptoTxId = '';
            if (is_array($body) && isset($body['data']['transaction_id'])) {
                $cryptoTxId = trim((string) $body['data']['transaction_id']);
            }
            if ($cryptoTxId === '') {
                $cryptoTxId = 'unknown';
            }

            $transaction->buyer_crypto_payout_tx_hash = $cryptoTxId;
            $this->persistPayoutAmlAndAlertOperatorIfNeeded(
                $lot,
                $transaction,
                'buyer_crypto_payout_to_seller',
                'buyer',
                $buyerEscrowToken,
                $cryptoType,
                $coreUrl,
                $coreKey,
                $resp->status(),
                is_array($body) ? $body : null,
            );
            Log::info('[exchange-lots] buyerCryptoPayoutToSeller: payout success', [
                'lot_id' => $lot->id,
                'transaction_id' => $transaction->id,
                'crypto_tx_id' => $cryptoTxId,
                'core_idempotent_replay' => is_array($body) ? ($body['data']['idempotent_replay'] ?? null) : null,
            ]);

            $sellerEmailForChat = $this->lotMailboxService->getMailboxEmailForAddress($lot->wallet_address ?? '', self::sellerMailboxCryptoCode($lot))
                ?? $lot->seller_lot_email;
            // Client uses via_escrow_server for localized chat summary; chat row body is empty (no user-facing server prose in app).
            $tagJson = json_encode([
                't' => 'buyer_crypto_tx',
                'lot_id' => (int) $lot->id,
                'tx_ref' => $cryptoTxId,
                'laravel_transaction_id' => $transaction->id,
                'via_escrow_server' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (! empty($sellerEmailForChat)) {
                try {
                    $this->sendDealEmail(
                        to: (string) $sellerEmailForChat,
                        subject: $this->getDealMessageSubject($lot),
                        fromAddress: config('mail.from.address', 'noreply@xdexboard.com'),
                        fromName: config('mail.from.name', 'XBoard'),
                        body: 'Buyer sent crypto to the seller address via escrow token.',
                        tagJson: $tagJson
                    );
                } catch (\Throwable $e) {
                    Log::warning('[exchange-lots] buyerCryptoPayoutToSeller: failed to send seller mail', [
                        'lot_id' => $lot->id,
                        'to' => $sellerEmailForChat,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            $this->insertDealChatMessage(
                $lot,
                'buyer',
                config('mail.from.address', 'noreply@xdexboard.com'),
                $sellerEmailForChat,
                '',
                $tagJson
            );

            return response()->json([
                'success' => true,
                'message' => 'Crypto sent to seller address. Seller verification step is now active.',
                'transaction_id' => $transaction->id,
                'crypto_tx_id' => $cryptoTxId,
                'destination_address' => $dest,
                'core_response' => is_array($body) ? $body : [],
            ]);
        });
    }

    /**
     * Seller: real escrow token balance from Core (for deal window). Same auth as chat (seller wallet match).
     */
    public function sellerDealEscrowBalance(Request $request, ExchangeLot $lot): JsonResponse
    {
        if (! $this->requesterIsSellerForLot($request, $lot)) {
            return response()->json(['success' => false, 'message' => 'Only the lot seller can load escrow balance.'], 403);
        }

        $escrowTokenPublic = $request->header('X-Escrow-Token') ?? $request->input('escrow_token');
        if (empty($escrowTokenPublic) || ! is_string($escrowTokenPublic)) {
            return response()->json(['success' => false, 'message' => 'escrow_token (public part) required.'], 422);
        }

        try {
            [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($escrowTokenPublic);
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] sellerDealEscrowBalance: core URL resolve failed', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Core configuration error.'], 502);
        }

        $timeout = (int) config('services.core.timeout', 30);
        $req = Http::timeout($timeout)->asJson();
        if ($coreKey !== '') {
            $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
        }
        $resp = $req->post(rtrim($coreUrl, '/').'/api/escrow-tokens/balance', [
            'escrow_token' => $escrowTokenPublic,
        ]);

        $json = $resp->json();
        if (! $resp->successful()) {
            $message = is_array($json) ? (string) ($json['message'] ?? 'Balance request failed') : $resp->body();

            return response()->json([
                'success' => false,
                'message' => $message,
                'error_code' => is_array($json) ? ($json['error_code'] ?? 'balance_failed') : 'balance_failed',
            ], $resp->status() >= 500 ? 502 : $resp->status());
        }

        return response()->json([
            'success' => true,
            'data' => is_array($json) ? ($json['data'] ?? $json) : [],
        ]);
    }

    /**
     * Seller: send reserved crypto to buyer address stored on the pending sale transaction (buyer-set-crypto-destination).
     * Core endpoint is API-only (X-Core-Key); destination is taken only from DB, not from client body.
     */
    public function sellerCryptoPayoutToBuyer(Request $request, ExchangeLot $lot): JsonResponse
    {
        if (! $this->requesterIsSellerForLot($request, $lot)) {
            return response()->json(['success' => false, 'message' => 'Only the lot seller can send crypto payout.'], 403);
        }

        if ($this->lotHasActiveDispute($lot)) {
            return response()->json([
                'success' => false,
                'message' => 'Crypto payout is not allowed while a dispute is open.',
                'error_code' => 'payout_blocked_active_dispute',
            ], 403);
        }

        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
        if ($buyerEmail === '') {
            return response()->json(['success' => false, 'message' => 'No buyer in deal.'], 400);
        }

        $sellerEscrowToken = $request->input('escrow_token') ?? $request->header('X-Escrow-Token');
        if (empty($sellerEscrowToken) || ! is_string($sellerEscrowToken)) {
            return response()->json([
                'success' => false,
                'message' => 'Full seller escrow_token required (X-Escrow-Token or body).',
            ], 422);
        }

        return DB::transaction(function () use ($lot, $buyerEmail, $sellerEscrowToken): JsonResponse {
            $transaction = Transaction::query()
                ->where('exchange_lot_id', $lot->id)
                ->where('buyer_lot_email', $buyerEmail)
                ->where('tx_type', 'sale')
                ->where('status', 'pending')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($transaction === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'No pending sale transaction for this deal.',
                    'error_code' => 'no_pending_tx',
                ], 400);
            }

            if (! empty($transaction->seller_crypto_payout_tx_hash)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Crypto payout already completed for this transaction.',
                    'error_code' => 'payout_already_sent',
                    'transaction_id' => $transaction->id,
                    'crypto_tx_id' => $transaction->seller_crypto_payout_tx_hash,
                ], 409);
            }

            $dest = trim((string) ($transaction->crypto_address ?? ''));
            if ($dest === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Buyer has not set a crypto destination address yet.',
                    'error_code' => 'buyer_crypto_address_missing',
                ], 400);
            }

            $amount = (float) $transaction->amount;
            if ($amount <= 0) {
                return response()->json(['success' => false, 'message' => 'Invalid transaction amount.'], 422);
            }

            try {
                $cryptoType = self::coreCryptoTypeFromEscrowTokenJson($sellerEscrowToken)
                    ?? self::coreCryptoTypeForSellerEscrow($lot);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            $addrError = self::validateStablecoinPayoutDestinationAddress($dest, $cryptoType);
            if ($addrError !== null) {
                return response()->json([
                    'success' => false,
                    'message' => $addrError['message'],
                    'error_code' => $addrError['error_code'],
                ], 422);
            }

            [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($sellerEscrowToken);
            $timeout = (int) config('services.core.payout_timeout', 120);
            $destinationPreview = strlen($dest) > 24 ? (substr($dest, 0, 24).'...') : $dest;
            $payload = [
                'exchange_lot_id' => (string) $lot->id,
                'laravel_transaction_id' => $transaction->id,
                'escrow_token' => $sellerEscrowToken,
                'destination_address' => $dest,
                'amount' => $amount,
                'crypto_type' => $cryptoType,
                'native_amount' => 0.0,
                'aml_required' => (bool) $lot->aml,
            ];
            $payoutNetwork = self::coreNetworkFromCryptoType($cryptoType);
            if ($payoutNetwork !== null) {
                $payload['network'] = $payoutNetwork;
            }

            $req = Http::timeout($timeout)->asJson();
            if ($coreKey !== '') {
                $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
            }
            $coreEndpoint = rtrim($coreUrl, '/').'/api/deal-payout-to-buyer-address';
            Log::info('[exchange-lots] sellerCryptoPayoutToBuyer: core payout start', [
                'lot_id' => $lot->id,
                'transaction_id' => $transaction->id,
                'core_endpoint' => $coreEndpoint,
                'timeout_seconds' => $timeout,
                'crypto_type' => $cryptoType,
                'amount' => $amount,
                'destination_preview' => $destinationPreview,
                'aml_required' => (bool) $lot->aml,
            ]);
            $startedAt = microtime(true);
            try {
                $resp = $req->post($coreEndpoint, $payload);
            } catch (\Throwable $e) {
                Log::warning('[exchange-lots] sellerCryptoPayoutToBuyer: core payout exception', [
                    'lot_id' => $lot->id,
                    'transaction_id' => $transaction->id,
                    'core_endpoint' => $coreEndpoint,
                    'timeout_seconds' => $timeout,
                    'took_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'exception_class' => $e::class,
                    'exception' => $e->getMessage(),
                ]);
                throw $e;
            }
            $responseBody = $resp->json();
            $responseBodyPreview = is_array($responseBody)
                ? json_encode($responseBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $resp->body();
            if (is_string($responseBodyPreview) && strlen($responseBodyPreview) > 500) {
                $responseBodyPreview = substr($responseBodyPreview, 0, 500).'...';
            }
            Log::info('[exchange-lots] sellerCryptoPayoutToBuyer: core payout response', [
                'lot_id' => $lot->id,
                'transaction_id' => $transaction->id,
                'took_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'core_http_status' => $resp->status(),
                'core_successful' => $resp->successful(),
                'core_transaction_id' => is_array($responseBody) ? ($responseBody['data']['transaction_id'] ?? null) : null,
                'core_idempotent_replay' => is_array($responseBody) ? ($responseBody['data']['idempotent_replay'] ?? null) : null,
                'core_body_preview' => $responseBodyPreview,
            ]);

            if (! $resp->successful()) {
                $body = $responseBody;
                $message = is_array($body) ? (string) ($body['message'] ?? 'Core payout failed') : $resp->body();
                $this->persistPayoutAmlAndAlertOperatorIfNeeded(
                    $lot,
                    $transaction,
                    'seller_crypto_payout_to_buyer',
                    'seller',
                    $sellerEscrowToken,
                    $cryptoType,
                    $coreUrl,
                    $coreKey,
                    $resp->status(),
                    is_array($body) ? $body : ['raw' => (string) $resp->body()],
                );
                Log::warning('[exchange-lots] sellerCryptoPayoutToBuyer: core payout non-success', [
                    'lot_id' => $lot->id,
                    'transaction_id' => $transaction->id,
                    'core_http_status' => $resp->status(),
                    'error_code' => is_array($body) ? ($body['error_code'] ?? 'core_payout_failed') : 'core_payout_failed',
                    'message' => $message,
                    'core_body_preview' => $responseBodyPreview,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'error_code' => is_array($body) ? ($body['error_code'] ?? 'core_payout_failed') : 'core_payout_failed',
                    'aml' => is_array($body) ? ($body['aml'] ?? null) : null,
                ], $resp->status() >= 500 ? 502 : 422);
            }

            $body = $responseBody;
            $cryptoTxId = '';
            if (is_array($body) && isset($body['data']['transaction_id'])) {
                $cryptoTxId = trim((string) $body['data']['transaction_id']);
            }
            if ($cryptoTxId === '') {
                $cryptoTxId = 'unknown';
            }

            $transaction->seller_crypto_payout_tx_hash = $cryptoTxId;
            $transaction->status = 'completed';
            $this->persistPayoutAmlAndAlertOperatorIfNeeded(
                $lot,
                $transaction,
                'seller_crypto_payout_to_buyer',
                'seller',
                $sellerEscrowToken,
                $cryptoType,
                $coreUrl,
                $coreKey,
                $resp->status(),
                is_array($body) ? $body : null,
            );
            Log::info('[exchange-lots] sellerCryptoPayoutToBuyer: payout success', [
                'lot_id' => $lot->id,
                'transaction_id' => $transaction->id,
                'crypto_tx_id' => $cryptoTxId,
                'core_idempotent_replay' => is_array($body) ? ($body['data']['idempotent_replay'] ?? null) : null,
            ]);

            try {
                $reqDel = Http::timeout((int) config('services.core.timeout', 10));
                if ($coreKey !== '') {
                    $reqDel = $reqDel->withHeaders(['X-Core-Key' => $coreKey]);
                }
                $reqDel->post(rtrim($coreUrl, '/').'/api/complete-reservation', [
                    'exchange_lot_id' => (string) $lot->id,
                    'escrow_token' => $sellerEscrowToken,
                    'crypto_type' => $cryptoType,
                    'aml_required' => (bool) $lot->aml,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[exchange-lots] sellerCryptoPayoutToBuyer: complete-reservation failed', [
                    'lot_id' => $lot->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $tagJson = json_encode([
                't' => 'deal_completed_seller',
                'lot_id' => (int) $lot->id,
                'crypto_payout_tx' => $cryptoTxId,
                'laravel_transaction_id' => $transaction->id,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            try {
                $this->sendDealEmail(
                    to: $buyerEmail,
                    subject: 'Deal completed',
                    fromAddress: config('mail.from.address', 'noreply@xdexboard.com'),
                    fromName: config('mail.from.name', 'XBoard'),
                    body: 'Seller sent crypto to the buyer address on file. Deal completed.',
                    tagJson: $tagJson
                );
            } catch (\Throwable $e) {
                Log::warning('[exchange-lots] sellerCryptoPayoutToBuyer: mailbox event send failed', [
                    'lot_id' => $lot->id,
                    'to' => $buyerEmail,
                    'error' => $e->getMessage(),
                ]);
            }

            $sellerEmailForChat = $this->lotMailboxService->getMailboxEmailForAddress($lot->wallet_address ?? '', self::sellerMailboxCryptoCode($lot)) ?? $lot->seller_lot_email;
            $chatPersisted = $this->insertDealChatMessage(
                $lot,
                'seller',
                $sellerEmailForChat ?? config('mail.from.address', 'noreply@xdexboard.com'),
                $buyerEmail,
                'Seller sent crypto to the buyer address on file. Deal completed.',
                $tagJson
            );
            $tagArr = json_decode($tagJson, true);
            $this->syncDisputeStateFromDealTag($lot, 'seller', is_array($tagArr) ? $tagArr : null);
            if (is_array($tagArr) && ($tagArr['t'] ?? '') === 'deal_completed_seller') {
                $lot->refresh();
                if (! $chatPersisted || ! $this->exchangeLotHasCompletedDealTagInChat($lot)) {
                    Log::error('[exchange-lots] seller crypto payout: deal_completed_seller tag not in chat history', [
                        'lot_id' => $lot->id,
                        'chat_persisted' => $chatPersisted,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Seller payout was sent but completion could not be saved to chat history.',
                        'error_code' => 'deal_chat_persist_failed',
                    ], 500);
                }
                DealSaleTransactionCompletion::markPendingSaleTransactionsCompleted($lot);
                DealTerminalLotClosure::releaseLotBuyerBinding($lot);
            }

            return response()->json([
                'success' => true,
                'message' => 'Crypto sent to buyer address. Deal marked completed.',
                'transaction_id' => $transaction->id,
                'crypto_tx_id' => $cryptoTxId,
                'destination_address' => $dest,
                'core_response' => is_array($body) ? $body : [],
            ]);
        });
    }

    /**
     * @param  array<string, mixed>|null  $responseBody
     */
    private function persistPayoutAmlAndAlertOperatorIfNeeded(
        ExchangeLot $lot,
        Transaction $transaction,
        string $payoutContext,
        string $screeningSide,
        string $escrowTokenJson,
        string $cryptoType,
        string $coreUrl,
        string $coreKey,
        int $httpStatus,
        ?array $responseBody,
    ): void {
        $this->dealAmlPersistence->handlePayoutCoreResponse(
            $lot,
            $transaction,
            $payoutContext,
            $screeningSide,
            $escrowTokenJson,
            $cryptoType,
            null,
            $httpStatus,
            $responseBody,
        );

        if (! is_array($responseBody) || (string) ($responseBody['error_code'] ?? '') !== 'AML_PROVIDER_UNAVAILABLE') {
            return;
        }

        $this->dealAmlAdminAlert->notifyProviderFailure(
            (int) $lot->id,
            (int) $transaction->id,
            $payoutContext,
            $screeningSide,
            $coreUrl,
            $coreKey,
            $httpStatus,
            $responseBody,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveCoreUrlAndKeyForArbiterPayout(ExchangeLot $lot): array
    {
        $raw = trim((string) ($lot->wallet_address ?? ''));
        if ($raw !== '' && str_starts_with($raw, '{')) {
            try {
                return $this->escrowTokenValidation->getCoreUrlAndKeyForToken($raw);
            } catch (\Throwable) {
                // fall through to default Core
            }
        }

        return [
            rtrim((string) config('services.core.url', ''), '/'),
            (string) config('services.core.key', ''),
        ];
    }

    /**
     * Payout buyer from seller escrow using Core reservations row (API key). Used when arbiter verdict is favor_buyer.
     *
     * @return array{success: bool, error: ?string, crypto_tx_id: ?string}
     */
    private function tryArbiterReservationPayoutToBuyer(ExchangeLot $lot): array
    {
        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
        if ($buyerEmail === '') {
            return ['success' => false, 'error' => 'no_buyer', 'crypto_tx_id' => null];
        }

        try {
            /** @var array{success: bool, error: ?string, crypto_tx_id: ?string} $out */
            $out = DB::transaction(function () use ($lot, $buyerEmail): array {
                $transaction = Transaction::query()
                    ->where('exchange_lot_id', $lot->id)
                    ->where('buyer_lot_email', $buyerEmail)
                    ->where('tx_type', 'sale')
                    ->where('status', 'pending')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                if ($transaction === null) {
                    return ['success' => false, 'error' => 'no_pending_tx', 'crypto_tx_id' => null];
                }

                if (! empty($transaction->seller_crypto_payout_tx_hash)) {
                    return [
                        'success' => true,
                        'error' => null,
                        'crypto_tx_id' => (string) $transaction->seller_crypto_payout_tx_hash,
                    ];
                }

                $dest = trim((string) ($transaction->crypto_address ?? ''));
                if ($dest === '') {
                    return ['success' => false, 'error' => 'buyer_crypto_address_missing', 'crypto_tx_id' => null];
                }

                $amount = (float) $transaction->amount;
                if ($amount <= 0) {
                    return ['success' => false, 'error' => 'invalid_amount', 'crypto_tx_id' => null];
                }

                try {
                    $cryptoType = self::coreCryptoTypeForSellerEscrow($lot);
                } catch (\InvalidArgumentException $e) {
                    return ['success' => false, 'error' => $e->getMessage(), 'crypto_tx_id' => null];
                }

                [$coreUrl, $coreKey] = $this->resolveCoreUrlAndKeyForArbiterPayout($lot);
                $timeout = (int) config('services.core.payout_timeout', 120);
                $payload = [
                    'exchange_lot_id' => (string) $lot->id,
                    'laravel_transaction_id' => $transaction->id,
                    'destination_address' => $dest,
                    'amount' => $amount,
                    'crypto_type' => $cryptoType,
                    'native_amount' => 0.0,
                    'aml_required' => (bool) $lot->aml,
                ];

                $req = Http::timeout($timeout)->asJson();
                if ($coreKey !== '') {
                    $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
                }
                $endpoint = rtrim($coreUrl, '/').'/api/deal-payout-to-buyer-from-reservation';
                Log::info('[exchange-lots] arbiterReservationPayout: core start', [
                    'lot_id' => $lot->id,
                    'transaction_id' => $transaction->id,
                    'endpoint' => $endpoint,
                ]);
                $resp = $req->post($endpoint, $payload);
                $responseBody = $resp->json();
                $sellerEscrowTokenForAml = trim((string) ($lot->wallet_address ?? ''));
                if (! $resp->successful()) {
                    $body = is_array($responseBody) ? $responseBody : [];
                    $msg = (string) ($body['message'] ?? $resp->body());
                    $this->persistPayoutAmlAndAlertOperatorIfNeeded(
                        $lot,
                        $transaction,
                        'arbiter_reservation_payout_to_buyer',
                        'seller',
                        $sellerEscrowTokenForAml,
                        $cryptoType,
                        $coreUrl,
                        $coreKey,
                        $resp->status(),
                        $body !== [] ? $body : ['raw' => (string) $resp->body()],
                    );

                    return [
                        'success' => false,
                        'error' => 'core_http_'.$resp->status().':'.$msg,
                        'crypto_tx_id' => null,
                    ];
                }

                $body = is_array($responseBody) ? $responseBody : [];
                $cryptoTxId = '';
                if (isset($body['data']['transaction_id'])) {
                    $cryptoTxId = trim((string) $body['data']['transaction_id']);
                }
                if ($cryptoTxId === '') {
                    $cryptoTxId = 'unknown';
                }

                $transaction->seller_crypto_payout_tx_hash = $cryptoTxId;
                $transaction->status = 'completed';
                $this->persistPayoutAmlAndAlertOperatorIfNeeded(
                    $lot,
                    $transaction,
                    'arbiter_reservation_payout_to_buyer',
                    'seller',
                    $sellerEscrowTokenForAml,
                    $cryptoType,
                    $coreUrl,
                    $coreKey,
                    $resp->status(),
                    $body,
                );

                $tagJson = json_encode([
                    't' => 'deal_completed_seller',
                    'lot_id' => (int) $lot->id,
                    'crypto_payout_tx' => $cryptoTxId,
                    'laravel_transaction_id' => $transaction->id,
                    'arbiter_reservation_payout' => true,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                try {
                    $this->sendDealEmail(
                        to: $buyerEmail,
                        subject: 'Deal completed',
                        fromAddress: config('mail.from.address', 'noreply@xdexboard.com'),
                        fromName: config('mail.from.name', 'XBoard'),
                        body: 'Seller escrow paid out to buyer address on file (reservation execution). Deal completed.',
                        tagJson: $tagJson
                    );
                } catch (\Throwable $e) {
                    Log::warning('[exchange-lots] arbiterReservationPayout: mailbox event send failed', [
                        'lot_id' => $lot->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $sellerEmailForChat = $this->lotMailboxService->getMailboxEmailForAddress($lot->wallet_address ?? '', self::sellerMailboxCryptoCode($lot)) ?? $lot->seller_lot_email;
                $this->insertDealChatMessage(
                    $lot,
                    'seller',
                    $sellerEmailForChat ?? config('mail.from.address', 'noreply@xdexboard.com'),
                    $buyerEmail,
                    'Seller escrow paid out to buyer address on file (reservation execution). Deal completed.',
                    $tagJson
                );
                $tagArr = json_decode($tagJson, true);
                $this->syncDisputeStateFromDealTag($lot, 'seller', is_array($tagArr) ? $tagArr : null);

                return ['success' => true, 'error' => null, 'crypto_tx_id' => $cryptoTxId];
            });

            return $out;
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] arbiterReservationPayout: failed', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage(), 'crypto_tx_id' => null];
        }
    }

    /**
     * Create a deal transaction and send payment details email to the buyer (seller only).
     * Subject is human-readable; structured tag is in X-XBoard-Deal-Tag header (JSON).
     * See docs/DEAL_EMAIL_SUBJECT_TAG.md.
     *
     * @OA\Post(
     *     path="/exchange-lots/{lot}/send-payment-details",
     *     summary="Send payment details to buyer (seller only)",
     *     tags={"Exchange Lots"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"payment_type"},
     *
     *         @OA\Property(property="payment_type", type="string", enum={"crypto","bank"}),
     *         @OA\Property(property="address", type="string", description="Crypto address (when payment_type=crypto)"),
     *         @OA\Property(property="crypto_type", type="string", description="e.g. xmr, xrp (when payment_type=crypto)"),
     *         @OA\Property(property="bank_swift", type="string", description="Bank SWIFT (when payment_type=bank, preferred)"),
     *         @OA\Property(property="bank_id", type="integer", description="Bank id (when payment_type=bank)"),
     *         @OA\Property(property="bank_data", type="string", description="Free-form transfer details (card, phone, etc.)")
     *     )),
     *
     *     @OA\Response(response=200, description="Transaction created, email sent"),
     *     @OA\Response(response=400, description="No buyer / validation error"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function sendPaymentDetails(Request $request, ExchangeLot $lot): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_type' => 'required|string|in:crypto,bank',
            'address' => 'required_if:payment_type,crypto|nullable|string|max:500',
            'crypto_type' => 'required_if:payment_type,crypto|nullable|string|max:30',
            'bank_data' => 'nullable|string|max:5000',
            'message' => 'nullable|string|max:10000', // Seller's text shown in chat (e.g. bank requisites as typed)
            'escrow_token' => 'nullable|string|max:10000', // Seller full token: used to set transaction_id on Core reservation
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $paymentType = $request->input('payment_type');
        // Bank is taken from the lot (buyer-selected bank in exchange_lots). We only need bank_data (transfer details).
        if ($paymentType === 'bank' && $lot->bank_id === null && ! SandboxVirtualBankService::lotUsesVirtualSandboxBank($lot)) {
            return response()->json([
                'success' => false,
                'message' => 'Lot has no bank selected for this deal.',
                'error_code' => 'validation_error',
            ], 422);
        }

        $lot->load(['cryptoCurrency', 'fromCryptoCurrency', 'toCryptoCurrency', 'bank', 'toFiatCurrency', 'fromFiatCurrency']);
        $buyerEmail = $lot->buyer_lot_email;
        if (empty($buyerEmail)) {
            Log::warning('[exchange-lots] sendPaymentDetails: no buyer in deal (e.g. buyer exited)', [
                'lot_id' => $lot->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No buyer in deal for this lot.',
                'error_code' => 'no_buyer_in_deal',
            ], 400);
        }

        $lot->refresh();
        $dealFlowState = DealChatTagSequenceValidator::computeStateForLot($lot);
        if (in_array($dealFlowState, [
            DealChatTagSequenceValidator::STATE_COMPLETED,
            DealChatTagSequenceValidator::STATE_BUYER_EXITED,
            DealChatTagSequenceValidator::STATE_SELLER_EXITED,
        ], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot send payment details: this deal is already finished or exited.',
                'error_code' => 'deal_payment_details_not_allowed_terminal_state',
            ], 422);
        }

        $amount = $lot->buyer_selected_amount > 0
            ? (float) $lot->buyer_selected_amount
            : (float) $lot->sell_amount;

        $sellerEscrowToken = $request->input('escrow_token') ?? $request->header('X-Escrow-Token');
        $coreSellerCryptoType = null;

        // First create reservation in Core (reserves amount on escrow token). Server error (e.g. insufficient balance) is returned to client.
        // Use Core URL from token environment (EscrowTokenValidationService) so sandbox/prod and salt match token creation.
        if (! empty($sellerEscrowToken) && is_string($sellerEscrowToken)) {
            try {
                $coreSellerCryptoType = self::coreCryptoTypeFromEscrowTokenJson($sellerEscrowToken)
                    ?? self::coreCryptoTypeForSellerEscrow($lot);
            } catch (\InvalidArgumentException $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'error_code' => 'invalid_lot_crypto',
                ], 422);
            }
            [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($sellerEscrowToken);
            $timeout = (int) config('services.core.timeout', 10);
            $req = Http::timeout($timeout);
            if ($coreKey !== '') {
                $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
            }
            $reservationPayload = [
                'exchange_lot_id' => $lot->id,
                'reserved_amount' => $amount,
                'escrow_token' => $sellerEscrowToken,
                'crypto_type' => $coreSellerCryptoType,
                'is_legal_entity' => false,
                'reservation_side' => 'seller',
            ];
            if (! empty($lot->buyer_escrow_token_hash)) {
                $reservationPayload['buyer_escrow_token_hash'] = $lot->buyer_escrow_token_hash;
            }
            $reservationResponse = $req->asJson()->post($coreUrl.'/api/reservations', $reservationPayload);
            if (! $reservationResponse->successful()) {
                $body = $reservationResponse->json();
                $message = is_array($body) && isset($body['message']) ? (string) $body['message'] : $reservationResponse->body();
                $errorCode = is_array($body) && isset($body['error_code']) ? (string) $body['error_code'] : 'reservation_failed';
                Log::warning('[exchange-lots] sendPaymentDetails: Core reservation failed', [
                    'lot_id' => $lot->id,
                    'status' => $reservationResponse->status(),
                    'message' => $message,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'error_code' => $errorCode,
                ], $reservationResponse->status() >= 500 ? 502 : $reservationResponse->status());
            }
        }

        $txHash = 'pd-'.$lot->id.'-'.now()->format('YmdHis').'-'.bin2hex(random_bytes(4));
        $txHash = substr($txHash, 0, 64);

        $bankId = $paymentType === 'bank' ? $lot->bank_id : null;
        $bankDetails = $paymentType === 'bank' ? trim((string) $request->input('bank_data', '')) : null;
        $cryptoAddress = $paymentType === 'crypto' ? trim((string) $request->input('address', '')) : null;

        $lot->refresh();
        $buyerSeenIpForSale = $lot->buyer_seen_ip;
        $buyerIpShareForSale = (bool) ($lot->buyer_ip_share_with_seller ?? false);

        $transaction = Transaction::create([
            'tx_hash' => $txHash,
            'tx_type' => 'sale',
            'from_address' => 'buyer',
            'to_address' => 'seller',
            'amount' => $amount,
            'exchange_lot_id' => $lot->id,
            'buyer_lot_email' => $buyerEmail,
            'buyer_seen_ip' => $buyerSeenIpForSale,
            'buyer_ip_share_with_seller' => $buyerIpShareForSale,
            'bank_id' => $bankId,
            'bank_details' => $bankDetails,
            'crypto_address' => $cryptoAddress,
            'payment_details_set_by' => 'seller',
            'transaction_timeout_minutes' => \App\Support\BuyerPaymentTimeoutMinutes::effectiveForLot(
                isset($lot->buyer_payment_timeout_minutes) ? (int) $lot->buyer_payment_timeout_minutes : null
            ),
            'status' => 'pending',
            'is_test' => (bool) $lot->is_test,
            'test_group' => $lot->test_group,
        ]);

        DB::table('exchange_lots')->where('id', $lot->id)->update([
            'buyer_seen_ip' => null,
            'buyer_ip_share_with_seller' => false,
        ]);

        if (! empty($sellerEscrowToken) && is_string($sellerEscrowToken)) {
            try {
                [$coreUrl, $coreKey] = $this->escrowTokenValidation->getCoreUrlAndKeyForToken($sellerEscrowToken);
                $timeout = (int) config('services.core.timeout', 10);
                $req = Http::timeout($timeout);
                if ($coreKey !== '') {
                    $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
                }
                $req->post($coreUrl.'/api/reservations/set-transaction-id', [
                    'exchange_lot_id' => $lot->id,
                    'transaction_id' => $transaction->id,
                    'escrow_token' => $sellerEscrowToken,
                    'crypto_type' => $coreSellerCryptoType,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[exchange-lots] sendPaymentDetails: Core set-transaction-id failed (non-blocking)', [
                    'lot_id' => $lot->id,
                    'transaction_id' => $transaction->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $tag = [
            't' => 'payment_details',
            'lot_id' => (int) $lot->id,
            'tx_id' => (int) $transaction->id,
        ];
        if ($paymentType === 'crypto') {
            $tag['crypto'] = trim((string) $request->input('crypto_type', ''));
            $tag['address'] = $cryptoAddress;
        } else {
            $tag['bank_id'] = SandboxVirtualBankService::lotUsesVirtualSandboxBank($lot)
                ? SandboxVirtualBankService::virtualBankId()
                : (int) $lot->bank_id;
            if ($bankDetails !== '') {
                $tag['bank_data'] = $bankDetails;
            }
        }
        $tagJson = json_encode($tag, JSON_UNESCAPED_UNICODE);

        $subject = 'Payment details — Lot #'.$lot->id;
        // Body = what the seller wrote (shown in chat). Technical data is in X-XBoard-Deal-Tag.
        $sellerMessage = trim((string) $request->input('message', ''));
        $body = $sellerMessage !== '' ? $sellerMessage : ('Payment details for Lot #'.$lot->id.'.');

        Log::info('[exchange-lots] sendPaymentDetails: sending mail', [
            'lot_id' => $lot->id,
            'transaction_id' => $transaction->id,
            'to' => $buyerEmail,
            'payment_type' => $paymentType,
        ]);

        try {
            Mail::raw($body, function ($message) use ($buyerEmail, $subject, $tagJson) {
                $message->to($buyerEmail);
                $message->subject($subject);
                $message->from(config('mail.from.address', 'noreply@xdexboard.com'), config('mail.from.name', 'XBoard'));
                $message->getHeaders()->addTextHeader('X-XBoard-Deal-Tag', $tagJson);
                HighDeliveryPriority::apply($message);
            });
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] sendPaymentDetails: failed to send mail', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send email: '.$e->getMessage(),
                'error_code' => 'mail_failed',
            ], 500);
        }

        if ($paymentType === 'bank' && SandboxVirtualBankService::lotUsesVirtualSandboxBank($lot)) {
            $this->sendSandboxVirtualBankReceiptEmail($lot, $transaction, $amount);
        }

        $sellerEmailForChat = $this->lotMailboxService->getMailboxEmailForAddress($lot->wallet_address ?? '', self::sellerMailboxCryptoCode($lot)) ?? $lot->seller_lot_email;
        $this->insertDealChatMessage(
            $lot,
            'seller',
            $sellerEmailForChat ?? config('mail.from.address'),
            $buyerEmail,
            $body,
            $tagJson
        );

        return response()->json([
            'success' => true,
            'message' => 'Payment details sent to buyer.',
            'transaction_id' => $transaction->id,
        ]);
    }

    /**
     * Test-only: send one email to the buyer identified by their escrow token.
     * Uses same Mail::raw path as sendMessageToBuyer. Only available when APP_ENV=local.
     * Token is the buyer's (from Laravel logs when buyer starts deal / sends message).
     */
    public function testSendToBuyer(Request $request): JsonResponse
    {
        if (app()->environment('production')) {
            return response()->json(['success' => false, 'message' => 'Not available.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:10000',
            'lot_id' => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $tokenData = $request->input('escrow_token_data');
        if (! is_array($tokenData)) {
            return response()->json([
                'success' => false,
                'message' => 'Missing escrow_token_data (valid X-Escrow-Token required).',
            ], 400);
        }

        $walletInput = $tokenData['wallet_address'] ?? null;
        if (is_array($walletInput)) {
            $walletInput = json_encode($walletInput);
        }
        $walletAddress = (string) ($walletInput ?? '');
        $currency = (string) ($tokenData['currency'] ?? '');
        if ($walletAddress === '' || $currency === '') {
            return response()->json([
                'success' => false,
                'message' => 'Token data missing wallet_address or currency.',
            ], 400);
        }

        $buyerEmail = $this->lotMailboxService->getMailboxEmailForAddress($walletAddress, $currency);
        if (! $buyerEmail) {
            return response()->json([
                'success' => false,
                'message' => 'No mailbox found for this token (buyer).',
            ], 400);
        }

        $messageText = $request->input('message', '');
        $lotId = (int) $request->input('lot_id', 0);
        $subject = $lotId > 0
            ? sprintf('Deal message — Lot #%s (test)', $lotId)
            : 'Deal message (test)';

        try {
            Mail::raw($messageText, function ($message) use ($buyerEmail, $subject) {
                $message->to($buyerEmail);
                $message->subject($subject);
                $message->from(config('mail.from.address', 'noreply@xdexboard.com'), config('mail.from.name', 'XBoard'));
                HighDeliveryPriority::apply($message);
            });
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] testSendToBuyer: failed to send mail', [
                'to' => $buyerEmail,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send: '.$e->getMessage(),
            ], 500);
        }

        Log::info('[exchange-lots] testSendToBuyer: mail sent', ['to' => $buyerEmail]);

        return response()->json([
            'success' => true,
            'message' => 'Message sent to buyer.',
            'to' => $buyerEmail,
        ]);
    }

    /**
     * Test-only: send one email to a buyer by email address (no token).
     * Use when you have buyer_lot_email from Laravel logs. Only when APP_ENV != production.
     */
    public function testSendToBuyerByEmail(Request $request): JsonResponse
    {
        if (app()->environment('production')) {
            return response()->json(['success' => false, 'message' => 'Not available.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'buyer_email' => 'required|string|email',
            'message' => 'required|string|max:10000',
            'lot_id' => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $buyerEmail = $request->input('buyer_email');
        $messageText = $request->input('message');
        $lotId = (int) $request->input('lot_id', 0);
        $subject = $lotId > 0
            ? sprintf('Deal message — Lot #%s (test)', $lotId)
            : 'Deal message (test)';

        try {
            Mail::raw($messageText, function ($message) use ($buyerEmail, $subject) {
                $message->to($buyerEmail);
                $message->subject($subject);
                $message->from(config('mail.from.address', 'noreply@xdexboard.com'), config('mail.from.name', 'XBoard'));
                HighDeliveryPriority::apply($message);
            });
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] testSendToBuyerByEmail: failed', [
                'to' => $buyerEmail,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send: '.$e->getMessage(),
            ], 500);
        }

        Log::info('[exchange-lots] testSendToBuyerByEmail: mail sent', ['to' => $buyerEmail]);

        return response()->json([
            'success' => true,
            'message' => 'Message sent to buyer.',
            'to' => $buyerEmail,
        ]);
    }

    /**
     * Get deal chat messages (thread) for the authenticated user (seller or buyer).
     * Reads from the requester's mailbox Maildir; returns messages with date, body, from_me.
     *
     * @OA\Get(
     *     path="/exchange-lots/{lot}/deal-messages",
     *     summary="Deal messages (chat thread)",
     *     tags={"Exchange Lots"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\Response(response=200, description="List of messages")
     * )
     */
    /**
     * @deprecated Reading is done on the client via IMAP only. This endpoint returns 410.
     */
    public function dealMessages(Request $request, ExchangeLot $lot): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Use IMAP on the client to read messages. This endpoint is deprecated.',
            'error_code' => 'use_imap_on_client',
        ], 410);
    }

    /**
     * True when request escrow token matches the lot seller wallet (same logic as chatMessages).
     */
    private function requesterIsSellerForLot(Request $request, ExchangeLot $lot): bool
    {
        $tokenData = $request->input('escrow_token_data', []);
        $requesterWallet = is_array($tokenData) ? ($tokenData['wallet_address'] ?? '') : '';
        $lot->load(['cryptoCurrency']);
        $lotWalletRaw = $lot->wallet_address !== null ? (json_decode((string) $lot->wallet_address, true)['wallet_address'] ?? $lot->wallet_address) : null;
        $requesterWalletStr = $this->normalizeWalletAddressForComparison($requesterWallet);
        $lotWalletStr = $lotWalletRaw !== null ? $this->normalizeWalletAddressForComparison($lotWalletRaw) : '';

        return $requesterWalletStr !== '' && $lotWalletStr !== '' && hash_equals($lotWalletStr, $requesterWalletStr);
    }

    private function requesterIsBuyerForLot(Request $request, ExchangeLot $lot): bool
    {
        $buyerTokenHash = strtolower(trim((string) ($lot->buyer_escrow_token_hash ?? '')));
        if ($buyerTokenHash === '') {
            return false;
        }
        $requestTokenHash = DealBuyerEscrowTokenHash::fromRequest($request);

        return $requestTokenHash !== '' && hash_equals($buyerTokenHash, $requestTokenHash);
    }

    /**
     * Normalize wallet_address (string or array from token/lot) for comparison. Lot stores full public part JSON; token_data may have wallet_address as array.
     */
    private function normalizeWalletAddressForComparison(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return trim((string) $value);
    }

    /**
     * Get deal chat messages for the lot. Requester must be seller or buyer (validated by escrow token).
     */
    public function chatMessages(Request $request, ExchangeLot $lot): JsonResponse
    {
        $tokenData = $request->input('escrow_token_data', []);
        $requesterWallet = is_array($tokenData) ? ($tokenData['wallet_address'] ?? '') : '';
        $tokenHash = is_array($tokenData) && isset($tokenData['token_hash']) ? trim((string) $tokenData['token_hash']) : '';
        $lot->load(['cryptoCurrency']);
        $lotWalletRaw = $lot->wallet_address !== null ? (json_decode((string) $lot->wallet_address, true)['wallet_address'] ?? $lot->wallet_address) : null;
        $requesterWalletStr = $this->normalizeWalletAddressForComparison($requesterWallet);
        $lotWalletStr = $lotWalletRaw !== null ? $this->normalizeWalletAddressForComparison($lotWalletRaw) : '';
        $isSeller = $requesterWalletStr !== '' && $lotWalletStr !== '' && hash_equals($lotWalletStr, $requesterWalletStr);
        $isBuyer = $tokenHash !== '' && $lot->buyer_escrow_token_hash !== null && strcasecmp(trim((string) $lot->buyer_escrow_token_hash), $tokenHash) === 0;
        if (! $isSeller && ! $isBuyer) {
            return response()->json(['success' => false, 'message' => 'Not authorized to read this chat.'], 403);
        }
        $query = DealChatMessage::where('exchange_lot_id', $lot->id);
        if ($isSeller) {
            $query->whereNull('deleted_by_seller_at');
        } else {
            $query->whereNull('deleted_by_buyer_at');
        }
        $messages = $query->orderBy('created_at')
            ->get()
            ->map(fn (DealChatMessage $m) => [
                'id' => $m->id,
                'date' => $this->toUtcIso8601($m->created_at) ?? '',
                'body' => $m->body,
                'from_me' => $isSeller ? $m->sender_side === 'seller' : $m->sender_side === 'buyer',
                'sender_side' => $m->sender_side,
                'deal_tag' => $m->deal_tag,
            ]);

        // Lets clients align step timers with device clock skew; all instants above are UTC regardless of app/DB TZ.
        return response()->json([
            'success' => true,
            'server_time' => $this->toUtcIso8601(now()),
            'messages' => $messages,
        ]);
    }

    /**
     * Get latest active dispute status for this lot.
     * Requester must be seller or buyer (same auth as chatMessages).
     */
    public function disputeStatus(Request $request, ExchangeLot $lot): JsonResponse
    {
        $tokenData = $request->input('escrow_token_data', []);
        $requesterWallet = is_array($tokenData) ? ($tokenData['wallet_address'] ?? '') : '';
        $tokenHash = is_array($tokenData) && isset($tokenData['token_hash']) ? trim((string) $tokenData['token_hash']) : '';
        $lot->load(['cryptoCurrency']);
        $lotWalletRaw = $lot->wallet_address !== null ? (json_decode((string) $lot->wallet_address, true)['wallet_address'] ?? $lot->wallet_address) : null;
        $requesterWalletStr = $this->normalizeWalletAddressForComparison($requesterWallet);
        $lotWalletStr = $lotWalletRaw !== null ? $this->normalizeWalletAddressForComparison($lotWalletRaw) : '';
        $isSeller = $requesterWalletStr !== '' && $lotWalletStr !== '' && hash_equals($lotWalletStr, $requesterWalletStr);
        $isBuyer = $tokenHash !== '' && $lot->buyer_escrow_token_hash !== null && strcasecmp(trim((string) $lot->buyer_escrow_token_hash), $tokenHash) === 0;
        if (! $isSeller && ! $isBuyer) {
            return response()->json(['success' => false, 'message' => 'Not authorized to read dispute status.'], 403);
        }

        $lot->refresh();
        $maxAiArbiter = (int) config('deal.max_ai_arbiter_invocations_per_lot', 30);
        $invocations = (int) ($lot->arbiter_ai_invocations ?? 0);

        $activeDispute = DealDispute::query()
            ->where('exchange_lot_id', $lot->id)
            ->whereIn('status', ['opened', 'in_review'])
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'success' => true,
            'dispute' => $activeDispute ? [
                'id' => $activeDispute->id,
                'status' => $activeDispute->status,
                'opened_by' => $activeDispute->opened_by,
                'opened_at' => $this->toUtcIso8601($activeDispute->opened_at),
                'resolved_by' => $activeDispute->resolved_by,
                'resolved_at' => $this->toUtcIso8601($activeDispute->resolved_at),
                'closed_at' => $this->toUtcIso8601($activeDispute->closed_at),
                'arbiter_id' => $activeDispute->arbiter_id,
                'arbiter_model_version' => $activeDispute->arbiter_model_version,
                'arbiter_verdict' => $activeDispute->arbiter_verdict,
                'last_activity_at' => $this->toUtcIso8601($activeDispute->last_activity_at),
            ] : null,
            'arbiter_ai' => [
                'invocations' => $invocations,
                'max_invocations' => $maxAiArbiter > 0 ? $maxAiArbiter : null,
                'limit_reached' => $maxAiArbiter > 0 && $invocations >= $maxAiArbiter,
            ],
        ]);
    }

    /**
     * Add AI arbiter message to deal chat (chat-only actor, no email delivery).
     * Requester must be seller or buyer (same auth as chatMessages).
     */
    public function requestArbiterMessage(Request $request, ExchangeLot $lot): JsonResponse
    {
        $tokenData = $request->input('escrow_token_data', []);
        $requesterWallet = is_array($tokenData) ? ($tokenData['wallet_address'] ?? '') : '';
        $tokenHash = is_array($tokenData) && isset($tokenData['token_hash']) ? trim((string) $tokenData['token_hash']) : '';
        $lot->load(['cryptoCurrency']);
        $lotWalletRaw = $lot->wallet_address !== null ? (json_decode((string) $lot->wallet_address, true)['wallet_address'] ?? $lot->wallet_address) : null;
        $requesterWalletStr = $this->normalizeWalletAddressForComparison($requesterWallet);
        $lotWalletStr = $lotWalletRaw !== null ? $this->normalizeWalletAddressForComparison($lotWalletRaw) : '';
        $isSeller = $requesterWalletStr !== '' && $lotWalletStr !== '' && hash_equals($lotWalletStr, $requesterWalletStr);
        $isBuyer = $tokenHash !== '' && $lot->buyer_escrow_token_hash !== null && strcasecmp(trim((string) $lot->buyer_escrow_token_hash), $tokenHash) === 0;
        if (! $isSeller && ! $isBuyer) {
            return response()->json(['success' => false, 'message' => 'Not authorized to request arbiter message.'], 403);
        }

        $maxAiArbiter = (int) config('deal.max_ai_arbiter_invocations_per_lot', 30);
        if ($maxAiArbiter > 0) {
            // Reserve before LLM: prevents replay loops that burn provider budget without inserting chat rows.
            $affected = DB::update(
                'UPDATE exchange_lots SET arbiter_ai_invocations = arbiter_ai_invocations + 1 WHERE id = ? AND arbiter_ai_invocations < ?',
                [(int) $lot->id, $maxAiArbiter]
            );
            if ($affected === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'AI arbiter invocation limit reached for this deal.',
                    'error_code' => 'ai_arbiter_iteration_limit_reached',
                    'max_ai_arbiter_invocations' => $maxAiArbiter,
                ], 422);
            }
        }

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:4000'],
            'provider' => ['nullable', 'string', 'max:64'],
            'provider_hint' => ['nullable', 'string', 'max:64'],
            'bank_timezone' => ['nullable', 'string', 'max:64'],
            'payment_time_raw' => ['nullable', 'string', 'max:128'],
            'pdf_sha256' => ['nullable', 'string', 'size:64', 'regex:/^[a-fA-F0-9]{64}$/'],
            'email_message_sha256' => ['nullable', 'string', 'size:64', 'regex:/^[a-fA-F0-9]{64}$/'],
            'dkim_signature_sha256' => ['nullable', 'string', 'size:64', 'regex:/^[a-fA-F0-9]{64}$/'],
        ]);

        $contextMessage = trim((string) ($validated['message'] ?? ''));
        $requesterSide = $isSeller ? 'seller' : 'buyer';
        $providerHint = trim((string) ($validated['provider_hint'] ?? $validated['provider'] ?? ''));
        $normalizedPaymentTime = $this->normalizeBankTimeToUtc(
            (string) ($validated['payment_time_raw'] ?? ''),
            (string) ($validated['bank_timezone'] ?? '')
        );

        $pdfSha256Norm = strtolower(trim((string) ($validated['pdf_sha256'] ?? '')));
        $fingerprints = array_filter([
            $pdfSha256Norm,
            strtolower(trim((string) ($validated['email_message_sha256'] ?? ''))),
            strtolower(trim((string) ($validated['dkim_signature_sha256'] ?? ''))),
        ]);
        $hasReplay = $this->hasEvidenceReplay($lot, $fingerprints);

        $receiptPdfAbsolutePath = $pdfSha256Norm !== ''
            ? ArbiterReceiptPdfResolver::resolvePathMatchingSha256($lot, $pdfSha256Norm)
            : null;

        $arbiterPromptContext = ArbiterPromptContextAssembler::forLot($lot, $requesterSide);

        $arbiterResult = $this->arbiterService->buildArbiterMessage(array_merge([
            'lot_id' => $lot->id,
            'requester_side' => $requesterSide,
            'message' => $contextMessage,
            'provider_hint' => $providerHint,
            'bank_timezone' => (string) ($validated['bank_timezone'] ?? ''),
            'payment_time_raw' => (string) ($validated['payment_time_raw'] ?? ''),
            'normalized_payment_time' => $normalizedPaymentTime,
            'pdf_sha256' => $pdfSha256Norm,
            'email_message_sha256' => strtolower(trim((string) ($validated['email_message_sha256'] ?? ''))),
            'dkim_signature_sha256' => strtolower(trim((string) ($validated['dkim_signature_sha256'] ?? ''))),
            'has_replay' => $hasReplay,
            'receipt_pdf_absolute_path' => $receiptPdfAbsolutePath,
        ], $arbiterPromptContext));
        $arbiterBody = (string) ($arbiterResult['message'] ?? 'Dispute is escalated to manual review.');
        $verdict = (string) ($arbiterResult['verdict'] ?? 'continue_review');
        $arbiterStatus = (string) ($arbiterResult['arbiter_status'] ?? '');

        Log::info('[arbiter] policy_applied', [
            'lot_id' => $lot->id,
            'provider_hint' => $providerHint !== '' ? $providerHint : null,
            'provider_used' => $arbiterResult['provider_used'] ?? null,
            'arbiter_status' => $arbiterResult['arbiter_status'] ?? null,
            'confidence' => $arbiterResult['confidence'] ?? null,
            'reason_code' => $arbiterResult['reason_code'] ?? null,
            'verdict' => $verdict,
            'has_replay' => $hasReplay,
        ]);

        $dispute = $this->ensureDealDisputeOpened(
            lot: $lot,
            openedBy: $requesterSide,
            arbiterId: 'arbiter_ai_v1',
            arbiterModelVersion: (string) ($arbiterResult['model_version'] ?? ''),
            status: 'in_review'
        );

        $resolveBuyerConfig = (bool) config('services.arbiter.resolve_on_favor_buyer', true);
        $resolveSellerConfig = (bool) config('services.arbiter.resolve_on_favor_seller', false);
        $forceCloseBuyerDisputeOnFavorSeller = (bool) config('services.arbiter.force_close_buyer_dispute_on_favor_seller', true);
        $autoCorePayout = (bool) config('services.arbiter.auto_core_payout_on_favor_buyer', false);

        $payoutResult = ['success' => false, 'error' => null, 'crypto_tx_id' => null];
        if ($verdict === 'favor_buyer' && $arbiterStatus === 'in_review' && $autoCorePayout) {
            $payoutResult = $this->tryArbiterReservationPayoutToBuyer($lot);
        }

        $disputeClosed = false;
        if ($verdict === 'favor_buyer' && $arbiterStatus === 'in_review') {
            $payoutOk = $autoCorePayout && ! empty($payoutResult['success']);
            if ($payoutOk) {
                $disputeClosed = true;
            } elseif ($autoCorePayout && empty($payoutResult['success'])) {
                $dispute->arbiter_verdict = $verdict;
                $dispute->save();
            } elseif (! $autoCorePayout && $resolveBuyerConfig) {
                $this->closeOpenDisputesForLot(
                    $lot,
                    'arbiter_ai',
                    'Arbiter: favor buyer. Dispute closed; seller may send crypto payout to buyer address on file.',
                    'favor_buyer'
                );
                $disputeClosed = true;
            } else {
                $dispute->arbiter_verdict = $verdict;
                $dispute->save();
            }
        } elseif (
            $verdict === 'favor_seller'
            && $arbiterStatus === 'in_review'
            && ($resolveSellerConfig || ($forceCloseBuyerDisputeOnFavorSeller && $dispute->opened_by === 'buyer'))
        ) {
            $this->closeOpenDisputesForLot(
                $lot,
                'arbiter_ai',
                'Arbiter: favor seller. Dispute closed.',
                'favor_seller'
            );
            $disputeClosed = true;
        } else {
            $dispute->arbiter_verdict = $verdict;
            $dispute->save();
        }

        $arbiterTag = json_encode([
            't' => 'arbiter_message',
            'lot_id' => (string) $lot->id,
            'arbiter_id' => 'arbiter_ai_v1',
            'arbiter_status' => $arbiterResult['arbiter_status'] ?? null,
            'arbiter_verdict' => $verdict,
            'reservation_payout' => [
                'attempted' => $autoCorePayout && $verdict === 'favor_buyer' && $arbiterStatus === 'in_review',
                'success' => ! empty($payoutResult['success']),
                'error' => $payoutResult['error'] ?? null,
                'crypto_tx_id' => $payoutResult['crypto_tx_id'] ?? null,
            ],
            'policy' => $arbiterResult['policy'] ?? 'rules_first',
            'provider_used' => $arbiterResult['provider_used'] ?? null,
            'provider_hint' => $providerHint !== '' ? $providerHint : null,
            'provider_hint_applied' => false,
            'reason_code' => $arbiterResult['reason_code'] ?? null,
            'confidence' => $arbiterResult['confidence'] ?? null,
            'operator_escalation_email_sent' => array_key_exists('operator_escalation_email_sent', $arbiterResult)
                ? (bool) $arbiterResult['operator_escalation_email_sent']
                : null,
            'dispute_closed' => $disputeClosed,
            'source_side' => $requesterSide,
            'dispute_id' => $dispute->id,
            'anti_replay' => [
                'triggered' => $hasReplay,
                'pdf_sha256' => $pdfSha256Norm,
                'email_message_sha256' => strtolower(trim((string) ($validated['email_message_sha256'] ?? ''))),
                'dkim_signature_sha256' => strtolower(trim((string) ($validated['dkim_signature_sha256'] ?? ''))),
            ],
            'bank_time' => [
                'timezone' => (string) ($validated['bank_timezone'] ?? ''),
                'raw' => (string) ($validated['payment_time_raw'] ?? ''),
                'normalized_utc' => $normalizedPaymentTime,
            ],
        ], JSON_UNESCAPED_UNICODE);

        $this->insertDealChatMessage(
            lot: $lot,
            senderSide: 'arbiter_ai',
            fromEmail: null,
            toEmail: null,
            body: $arbiterBody,
            dealTag: $arbiterTag !== false ? $arbiterTag : null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Arbiter message added.',
            'data' => [
                'sender_side' => 'arbiter_ai',
                'body' => $arbiterBody,
                'dispute_id' => $dispute->id,
                'dispute_status' => $disputeClosed ? 'closed' : 'in_review',
                'dispute_closed' => $disputeClosed,
                'arbiter_verdict' => $verdict,
                'arbiter_status' => $arbiterResult['arbiter_status'] ?? null,
                'confidence' => $arbiterResult['confidence'] ?? null,
                'reason_code' => $arbiterResult['reason_code'] ?? null,
                'operator_escalation_email_sent' => array_key_exists('operator_escalation_email_sent', $arbiterResult)
                    ? (bool) $arbiterResult['operator_escalation_email_sent']
                    : null,
                'provider_used' => $arbiterResult['provider_used'] ?? null,
                'provider_hint_applied' => false,
                'reservation_payout' => [
                    'attempted' => $autoCorePayout && $verdict === 'favor_buyer' && $arbiterStatus === 'in_review',
                    'success' => ! empty($payoutResult['success']),
                    'error' => $payoutResult['error'] ?? null,
                    'crypto_tx_id' => $payoutResult['crypto_tx_id'] ?? null,
                ],
            ],
        ]);
    }

    /**
     * Get deal message delivery/read status for the buyer: has the seller received and read the start_deal message?
     * API scans seller's Maildir (wallet_address from lot). Buyer polls this to show "Delivering / Delivered / Read".
     * Requester must be seller or buyer (same auth as chatMessages).
     */
    public function dealMessageStatus(Request $request, ExchangeLot $lot): JsonResponse
    {
        $tokenData = $request->input('escrow_token_data', []);
        $requesterWallet = is_array($tokenData) ? ($tokenData['wallet_address'] ?? '') : '';
        $tokenHash = is_array($tokenData) && isset($tokenData['token_hash']) ? trim((string) $tokenData['token_hash']) : '';
        $lot->load(['cryptoCurrency', 'fromCryptoCurrency', 'toCryptoCurrency']);
        $lotWalletRaw = $lot->wallet_address !== null ? (json_decode((string) $lot->wallet_address, true)['wallet_address'] ?? $lot->wallet_address) : null;
        $requesterWalletStr = $this->normalizeWalletAddressForComparison($requesterWallet);
        $lotWalletStr = $lotWalletRaw !== null ? $this->normalizeWalletAddressForComparison($lotWalletRaw) : '';
        $isSeller = $requesterWalletStr !== '' && $lotWalletStr !== '' && hash_equals($lotWalletStr, $requesterWalletStr);
        $isBuyer = $tokenHash !== '' && $lot->buyer_escrow_token_hash !== null && strcasecmp(trim((string) $lot->buyer_escrow_token_hash), $tokenHash) === 0;
        if (! $isSeller && ! $isBuyer) {
            return response()->json(['success' => false, 'message' => 'Not authorized.'], 403);
        }
        $walletAddress = (string) ($lot->wallet_address ?? '');
        $currency = self::sellerMailboxCryptoCode($lot);
        if ($walletAddress === '') {
            return response()->json(['success' => true, 'delivered' => false, 'read' => false]);
        }
        $status = $this->lotMailboxService->getDealMessageStatus($walletAddress, $currency, (string) $lot->id);
        if ($status === null) {
            return response()->json(['success' => true, 'delivered' => false, 'read' => false]);
        }

        return response()->json([
            'success' => true,
            'delivered' => (bool) ($status['delivered'] ?? false),
            'read' => (bool) ($status['read'] ?? false),
        ]);
    }

    /**
     * Mark chat messages as cleared for this requester only (per-side soft delete).
     * Seller clearing sets deleted_by_seller_at (messages hidden only for seller).
     * Buyer clearing sets deleted_by_buyer_at (messages hidden only for buyer).
     */
    public function markChatCleared(Request $request, ExchangeLot $lot): JsonResponse
    {
        $tokenData = $request->input('escrow_token_data', []);
        $requesterWallet = is_array($tokenData) ? ($tokenData['wallet_address'] ?? '') : '';
        $tokenHash = is_array($tokenData) && isset($tokenData['token_hash']) ? trim((string) $tokenData['token_hash']) : '';
        $lot->load(['cryptoCurrency']);
        $lotWalletRaw = $lot->wallet_address !== null ? (json_decode((string) $lot->wallet_address, true)['wallet_address'] ?? $lot->wallet_address) : null;
        $requesterWalletStr = $this->normalizeWalletAddressForComparison($requesterWallet);
        $lotWalletStr = $lotWalletRaw !== null ? $this->normalizeWalletAddressForComparison($lotWalletRaw) : '';
        $isSeller = $requesterWalletStr !== '' && $lotWalletStr !== '' && hash_equals($lotWalletStr, $requesterWalletStr);
        $isBuyer = $tokenHash !== '' && $lot->buyer_escrow_token_hash !== null && strcasecmp(trim((string) $lot->buyer_escrow_token_hash), $tokenHash) === 0;
        if (! $isSeller && ! $isBuyer) {
            return response()->json(['success' => false, 'message' => 'Not authorized to clear this chat.'], 403);
        }
        $clearQuery = DealChatMessage::where('exchange_lot_id', $lot->id);
        \App\Support\DealChatMessageVisibility::applyClearableByRequester($clearQuery, $isSeller);
        $count = $clearQuery->update([$isSeller ? 'deleted_by_seller_at' : 'deleted_by_buyer_at' => now()]);
        Log::info('[exchange-lots] markChatCleared', ['lot_id' => $lot->id, 'by' => $isSeller ? 'seller' : 'buyer', 'count' => $count]);

        return response()->json(['success' => true, 'message' => 'Chat marked as cleared.', 'count' => $count]);
    }

    /**
     * Remove PII-heavy server-side artifacts for a successfully completed deal.
     * Seller or buyer (escrow token) may call. Deletes deal chat rows, transaction rows, linked receipt files,
     * and clears lot mailboxes.
     * Clears buyer_lot_email and buyer_selected_amount; buyer_escrow_token_hash is retained so chat-messages can still authorize the same token and return [].
     * Best-effort: also calls Core complete-reservation / complete-reservation-by-buyer so escrow reservations are not left orphaned.
     */
    public function purgeCompletedDealData(Request $request, ExchangeLot $lot): JsonResponse
    {
        $tokenData = $request->input('escrow_token_data', []);
        $requesterWallet = is_array($tokenData) ? ($tokenData['wallet_address'] ?? '') : '';
        $tokenHash = is_array($tokenData) && isset($tokenData['token_hash']) ? trim((string) $tokenData['token_hash']) : '';
        $lot->load(['cryptoCurrency']);
        $lotWalletRaw = $lot->wallet_address !== null ? (json_decode((string) $lot->wallet_address, true)['wallet_address'] ?? $lot->wallet_address) : null;
        $requesterWalletStr = $this->normalizeWalletAddressForComparison($requesterWallet);
        $lotWalletStr = $lotWalletRaw !== null ? $this->normalizeWalletAddressForComparison($lotWalletRaw) : '';
        $isSeller = $requesterWalletStr !== '' && $lotWalletStr !== '' && hash_equals($lotWalletStr, $requesterWalletStr);
        $isBuyer = $tokenHash !== '' && $lot->buyer_escrow_token_hash !== null && strcasecmp(trim((string) $lot->buyer_escrow_token_hash), $tokenHash) === 0;
        if (! $isSeller && ! $isBuyer) {
            return response()->json(['success' => false, 'message' => 'Not authorized to purge this deal.'], 403);
        }

        if (! $this->exchangeLotHasCompletedDealTagInChat($lot)
            && ! $this->exchangeLotBuyerBindingAlreadyReleased($lot)) {
            return response()->json([
                'success' => false,
                'message' => 'Deal is not in a removable state (no terminal tag in chat history).',
                'error_code' => 'deal_not_completed',
            ], 409);
        }

        $validated = $request->validate([
            'purge_notification_subject' => ['nullable', 'string', 'max:998'],
            'purge_notification_body' => ['nullable', 'string', 'max:16000'],
            'delete_transactions' => ['sometimes', 'boolean'],
        ]);
        $deleteTransactions = array_key_exists('delete_transactions', $validated)
            ? (bool) $validated['delete_transactions']
            : true;

        $sellerNotify = $lot->seller_notification_email ? trim((string) $lot->seller_notification_email) : '';
        $buyerNotifyRaw = $lot->getAttribute('buyer_notification_email');
        $buyerNotify = is_string($buyerNotifyRaw) ? trim($buyerNotifyRaw) : '';

        $notifiedRecipients = [];

        try {
            DB::transaction(function () use ($lot, $deleteTransactions): void {
                $lot->refresh();

                $deletedTransactions = 0;
                if ($deleteTransactions) {
                    $transactions = Transaction::query()->where('exchange_lot_id', $lot->id)->get();
                    foreach ($transactions as $tx) {
                        if ($tx->delete()) {
                            $deletedTransactions++;
                        }
                    }
                }
                Log::info('[exchange-lots] purgeCompletedDealData: transactions deleted', [
                    'lot_id' => $lot->id,
                    'deleted' => $deletedTransactions,
                    'delete_transactions' => $deleteTransactions,
                ]);

                DealChatMessage::query()->where('exchange_lot_id', $lot->id)->delete();

                $currency = self::sellerMailboxCryptoCode($lot);
                if (! empty($lot->wallet_address)) {
                    $this->lotMailboxService->cleanupMailbox((string) $lot->wallet_address, $currency);
                }
                $sellerLotMailbox = trim((string) ($lot->seller_lot_email ?? ''));
                if ($sellerLotMailbox !== '') {
                    $this->lotMailboxService->cleanupMailboxByEmail($sellerLotMailbox);
                }
                $buyerLotEmail = trim((string) ($lot->buyer_lot_email ?? ''));
                if ($buyerLotEmail !== '') {
                    $this->lotMailboxService->cleanupMailboxByEmail($buyerLotEmail);
                }

                $this->closeOpenDisputesForLot(
                    lot: $lot,
                    resolvedBy: 'system',
                    resolution: 'Deal data purged after completion.'
                );

                // Clear mailbox-oriented PII; keep buyer_escrow_token_hash so ValidateEscrowToken + chat-messages
                // still recognize the buyer and return an empty thread (otherwise GET chat-messages → 403 and clients keep stale UI).
                ExchangeLot::query()->where('id', $lot->id)->update([
                    'buyer_lot_email' => null,
                    'buyer_selected_amount' => null,
                    'buyer_seen_ip' => null,
                    'buyer_ip_share_with_seller' => false,
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('[exchange-lots] purgeCompletedDealData failed', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Purge failed.',
                'error_code' => 'purge_failed',
            ], 500);
        }

        $this->tryReleaseCoreReservationsOnPurge($lot, $request, $isBuyer, $isSeller);

        $recipients = [];
        if ($sellerNotify !== '') {
            $recipients[strtolower($sellerNotify)] = $sellerNotify;
        }
        if ($buyerNotify !== '') {
            $recipients[strtolower($buyerNotify)] = $buyerNotify;
        }

        $subject = trim((string) ($validated['purge_notification_subject'] ?? ''));
        $body = trim((string) ($validated['purge_notification_body'] ?? ''));
        if ($subject === '' || $body === '') {
            // Legacy clients; fixed English only (no server-side locale maps).
            $subject = sprintf('XBoard — Lot #%s: deal data removed from server', (string) $lot->id);
            $body = sprintf(
                "Automated notice from XBoard.\n\n".
                "Exchange lot #%s: a participant removed deal artifacts from the exchange server.\n".
                "Cleared where applicable: deal chat messages in the database, transactions and linked receipt PDF files, and message files in the lot mailboxes on the mail server.\n\n".
                "This email was sent because this address is stored as a notification email for this lot.\n",
                (string) $lot->id
            );
        }

        foreach ($recipients as $to) {
            try {
                $fromAddress = config('mail.from.address', 'noreply@xdexboard.com');
                $fromName = config('mail.from.name', 'XBoard');
                $htmlBody = $this->buildSellerExternalNotificationHtml($body, null, null, null, null);
                $this->sendDealEmailHtmlMultipart(
                    to: $to,
                    subject: $subject,
                    fromAddress: $fromAddress,
                    fromName: $fromName,
                    plainBody: $body,
                    htmlBody: $htmlBody,
                    tagJson: null
                );
                $notifiedRecipients[] = $to;
            } catch (\Throwable $e) {
                Log::warning('[exchange-lots] purgeCompletedDealData: notification mail failed', [
                    'to' => $to,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Deal data purged.',
            'notified_addresses' => $notifiedRecipients,
            'transactions_deleted' => $deleteTransactions,
        ]);
    }

    /**
     * After completion or a prior purge, buyer_lot_email is cleared while buyer_escrow_token_hash stays for chat auth.
     */
    private function exchangeLotBuyerBindingAlreadyReleased(ExchangeLot $lot): bool
    {
        $hadBuyer = trim((string) ($lot->buyer_escrow_token_hash ?? '')) !== '';

        return $hadBuyer && trim((string) ($lot->buyer_lot_email ?? '')) === '';
    }

    /**
     * Best-effort Core reservation release when purging API deal artifacts.
     * Reservations live in Core, not exchange_lots.
     */
    private function tryReleaseCoreReservationsOnPurge(ExchangeLot $lot, Request $request, bool $isBuyer, bool $isSeller): void
    {
        if ($isBuyer) {
            $this->tryDeleteCoreReservationAfterBuyerAmicableCompletion($lot, $request, 'purge_completed_deal_data');
        }

        $lotSellerToken = trim((string) ($lot->wallet_address ?? ''));
        if ($lotSellerToken !== '' && str_starts_with($lotSellerToken, '{')) {
            $this->postCoreCompleteReservationSeller($lot, $lotSellerToken, 'purge_completed_deal_data');
        } elseif ($isSeller) {
            $this->tryDeleteCoreReservationAfterSellerAmicableCompletion($lot, $request, 'purge_completed_deal_data');
        }
    }

    private function exchangeLotHasCompletedDealTagInChat(ExchangeLot $lot): bool
    {
        $lotId = (string) $lot->id;
        $terminalTags = [
            'deal_completed_buyer',
            'deal_completed_seller',
            'deal_completed_seller_timeout',
            'buyer_exited',
            'seller_exited',
        ];
        $tags = DealChatMessage::query()
            ->where('exchange_lot_id', $lot->id)
            ->whereNotNull('deal_tag')
            ->pluck('deal_tag');
        foreach ($tags as $raw) {
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            $d = json_decode($raw, true);
            if (! is_array($d)) {
                continue;
            }
            if (! in_array((string) ($d['t'] ?? ''), $terminalTags, true)) {
                continue;
            }
            if ((string) ($d['lot_id'] ?? '') !== $lotId) {
                continue;
            }

            return true;
        }

        // Fallback: some terminal flows (notably seller_exited when buyer mailbox is already cleared)
        // historically did not persist a chat row. Allow purge only when the lot no longer looks "active".
        $lot->refresh();
        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
        if ($buyerEmail !== '') {
            return false;
        }
        $pendingSale = Transaction::query()
            ->where('exchange_lot_id', $lot->id)
            ->where('tx_type', 'sale')
            ->where('status', 'pending')
            ->exists();
        if ($pendingSale) {
            return false;
        }

        return true;
    }

    /**
     * Keep deal_disputes in sync with existing chat/message actions.
     *
     * @param  array<string, mixed>|null  $dealTagData
     */
    private function syncDisputeStateFromDealTag(
        ExchangeLot $lot,
        string $senderSide,
        ?array $dealTagData,
        ?string $messageType = null
    ): void {
        if (in_array($messageType, ['buyer_exited', 'seller_exited'], true)) {
            $this->closeOpenDisputesForLot(
                lot: $lot,
                resolvedBy: 'system',
                resolution: 'Deal was exited by one side.'
            );

            return;
        }

        if ($dealTagData === null) {
            return;
        }

        $tagType = isset($dealTagData['t']) ? trim((string) $dealTagData['t']) : '';
        if (in_array($tagType, ['dispute_requested', 'dispute_requested_by_buyer', 'dispute_requested_by_seller'], true)) {
            $this->ensureDealDisputeOpened(
                lot: $lot,
                openedBy: $senderSide,
                status: 'opened'
            );

            return;
        }

        if (in_array($tagType, ['deal_completed_seller', 'deal_completed_buyer', 'deal_completed_seller_timeout'], true)) {
            $this->closeOpenDisputesForLot(
                lot: $lot,
                resolvedBy: $senderSide,
                resolution: 'Deal completed by participants.'
            );
        }
    }

    /**
     * @param  array<int, float|int|string>  $buyAmounts
     */
    private function validateEscrowMultisig2Of3LotRules(
        bool $required,
        array $buyAmounts,
        ?CryptoCurrency $fromCrypto,
        ?CryptoCurrency $toCrypto,
    ): ?JsonResponse {
        if (! $required) {
            return null;
        }
        if (! $this->cryptoPairAllowsEscrowMultisig2Of3($fromCrypto, $toCrypto)) {
            return response()->json([
                'success' => false,
                'errors' => [
                    'escrow_multisig_2_of_3_required' => [
                        '2-of-3 multisig escrow is only allowed when the lot involves XRP, XMR, Stellar (code ending with _stellar), or Tron TRC-20 (code ending with _tron) on at least one side. Other networks are not enabled yet.',
                    ],
                ],
            ], 422);
        }
        if (count($buyAmounts) !== 1) {
            return response()->json([
                'success' => false,
                'errors' => [
                    'buy_amounts' => [
                        'When 2-of-3 multisig escrow is required, specify exactly one buy amount: the full lot only (no tiers, no partial tranches).',
                    ],
                ],
            ], 422);
        }

        return null;
    }

    private function cryptoCurrencyCodeAllowsEscrowMultisig2Of3(?CryptoCurrency $c): bool
    {
        if ($c === null) {
            return false;
        }
        $code = strtolower(trim((string) ($c->code ?? '')));
        if ($code === '') {
            return false;
        }
        if ($code === 'xrp' || $code === 'xmr') {
            return true;
        }

        return str_ends_with($code, '_stellar') || str_ends_with($code, '_tron');
    }

    private function cryptoPairAllowsEscrowMultisig2Of3(?CryptoCurrency $fromCrypto, ?CryptoCurrency $toCrypto): bool
    {
        return $this->cryptoCurrencyCodeAllowsEscrowMultisig2Of3($fromCrypto)
            || $this->cryptoCurrencyCodeAllowsEscrowMultisig2Of3($toCrypto);
    }

    private function normalizeBankTimeToUtc(string $rawValue, string $timezone): ?string
    {
        $raw = trim($rawValue);
        $tz = trim($timezone);
        if ($raw === '' || $tz === '') {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($raw, new \DateTimeZone($tz));

            return $dt->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Serialize instants for JSON as ISO-8601 in UTC (Z/+00:00), regardless of APP_TIMEZONE or MySQL session TZ.
     */
    private function toUtcIso8601(?\Carbon\CarbonInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value->clone()->utc()->toIso8601String();
    }

    /**
     * @param  list<string>  $fingerprints
     */
    private function hasEvidenceReplay(ExchangeLot $lot, array $fingerprints): bool
    {
        if (count($fingerprints) === 0) {
            return false;
        }

        $normalizedFingerprints = array_values(array_unique(array_map(
            static fn (string $v): string => strtolower(trim($v)),
            $fingerprints
        )));

        $messages = DealChatMessage::query()
            ->where('exchange_lot_id', $lot->id)
            ->where('sender_side', 'arbiter_ai')
            ->whereNotNull('deal_tag')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['deal_tag']);

        foreach ($messages as $message) {
            $tagRaw = (string) ($message->deal_tag ?? '');
            if ($tagRaw === '') {
                continue;
            }
            $tagData = json_decode($tagRaw, true);
            if (! is_array($tagData)) {
                continue;
            }
            $antiReplay = is_array($tagData['anti_replay'] ?? null) ? $tagData['anti_replay'] : [];
            $existing = array_filter([
                strtolower(trim((string) ($antiReplay['pdf_sha256'] ?? ''))),
                strtolower(trim((string) ($antiReplay['email_message_sha256'] ?? ''))),
                strtolower(trim((string) ($antiReplay['dkim_signature_sha256'] ?? ''))),
            ]);
            if (count(array_intersect($normalizedFingerprints, $existing)) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Open a dispute if none active; otherwise update the latest active one.
     */
    private function ensureDealDisputeOpened(
        ExchangeLot $lot,
        string $openedBy,
        string $status = 'opened',
        ?string $arbiterId = null,
        ?string $arbiterModelVersion = null
    ): DealDispute {
        $active = DealDispute::query()
            ->where('exchange_lot_id', $lot->id)
            ->whereIn('status', ['opened', 'in_review'])
            ->orderByDesc('id')
            ->first();

        if ($active !== null) {
            $active->status = $status === 'in_review' ? 'in_review' : $active->status;
            $active->last_activity_at = now();
            if ($arbiterId !== null && $arbiterId !== '') {
                $active->arbiter_id = $arbiterId;
            }
            if ($arbiterModelVersion !== null && $arbiterModelVersion !== '') {
                $active->arbiter_model_version = $arbiterModelVersion;
            }
            $active->save();

            return $active;
        }

        return DealDispute::create([
            'exchange_lot_id' => $lot->id,
            'opened_by' => $openedBy,
            'status' => $status,
            'arbiter_id' => $arbiterId,
            'arbiter_model_version' => $arbiterModelVersion,
            'opened_at' => now(),
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Deal exit/cancel lock based on pending transactions for current buyer.
     * Returns lock payload when at least one transaction is still within timeout window, otherwise null.
     *
     * Timeout is per transaction (not global for whole deal):
     * - if transaction.transaction_timeout_minutes is set, use it;
     * - otherwise fallback to lot.buyer_payment_timeout_minutes (see BuyerPaymentTimeoutMinutes).
     *
     * @return array{transaction_id:int, remaining_seconds:int, release_at:string}|null
     */
    private function getActiveDealExitLock(ExchangeLot $lot): ?array
    {
        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
        if ($buyerEmail === '') {
            return null;
        }

        $now = now();
        $transactions = Transaction::query()
            ->where('exchange_lot_id', $lot->id)
            ->where('buyer_lot_email', $buyerEmail)
            ->where('tx_type', 'sale')
            ->where('status', 'pending')
            ->get();

        if ($transactions->isEmpty()) {
            return null;
        }

        $activeLock = null;
        foreach ($transactions as $transaction) {
            $timeoutMinutes = (int) ($transaction->transaction_timeout_minutes ?? \App\Support\BuyerPaymentTimeoutMinutes::effectiveForLot(
                isset($lot->buyer_payment_timeout_minutes) ? (int) $lot->buyer_payment_timeout_minutes : null
            ));
            if ($timeoutMinutes <= 0) {
                $timeoutMinutes = 30;
            }
            $releaseAt = $transaction->created_at?->copy()->addMinutes($timeoutMinutes);
            if ($releaseAt === null || $releaseAt->lessThanOrEqualTo($now)) {
                continue;
            }

            $remaining = (int) $now->diffInSeconds($releaseAt, false);
            if ($remaining <= 0) {
                continue;
            }

            if ($activeLock === null || $remaining > $activeLock['remaining_seconds']) {
                $activeLock = [
                    'transaction_id' => (int) $transaction->id,
                    'remaining_seconds' => $remaining,
                    'release_at' => $this->toUtcIso8601($releaseAt),
                ];
            }
        }

        return $activeLock;
    }

    /**
     * Returns who is allowed to exit/cancel after timeout based on
     * who provided payment details for the latest deal transaction.
     *
     * @return array{allowed_side:string, transaction_id:int}|null
     */
    private function getDealExitPermissionForTimeout(ExchangeLot $lot): ?array
    {
        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
        if ($buyerEmail === '') {
            return null;
        }

        $transaction = Transaction::query()
            ->where('exchange_lot_id', $lot->id)
            ->where('buyer_lot_email', $buyerEmail)
            ->where('tx_type', 'sale')
            ->orderByDesc('id')
            ->first();

        if ($transaction === null) {
            return null;
        }

        $rawSide = strtolower(trim((string) ($transaction->payment_details_set_by ?? '')));
        $allowedSide = in_array($rawSide, ['buyer', 'seller'], true) ? $rawSide : 'seller';

        return [
            'allowed_side' => $allowedSide,
            'transaction_id' => (int) $transaction->id,
        ];
    }

    private function lotHasActiveDispute(ExchangeLot $lot): bool
    {
        return DealDispute::query()
            ->where('exchange_lot_id', $lot->id)
            ->whereIn('status', ['opened', 'in_review'])
            ->exists();
    }

    /**
     * During an active dispute we keep exit/cancel locked for compensation review window.
     * After the window expires, normal legacy exit rules apply again.
     *
     * @return array{remaining_seconds:int,release_at:string}|null
     */
    private function getActiveDisputeExitLock(ExchangeLot $lot): ?array
    {
        $active = DealDispute::query()
            ->where('exchange_lot_id', $lot->id)
            ->whereIn('status', ['opened', 'in_review'])
            ->orderByDesc('id')
            ->first();
        if ($active === null) {
            return null;
        }

        $hours = max(1, (int) config('deal.active_dispute_exit_lock_hours', 24));
        $openedAt = $active->opened_at ?? $active->created_at ?? now();
        $releaseAt = $openedAt->copy()->addHours($hours);
        if ($releaseAt->isPast()) {
            return null;
        }

        return [
            'remaining_seconds' => (int) now()->diffInSeconds($releaseAt),
            'release_at' => $this->toUtcIso8601($releaseAt),
        ];
    }

    /**
     * Close all active disputes for the lot.
     */
    private function closeOpenDisputesForLot(
        ExchangeLot $lot,
        string $resolvedBy,
        string $resolution,
        ?string $arbiterVerdict = null
    ): void {
        DealDispute::query()
            ->where('exchange_lot_id', $lot->id)
            ->whereIn('status', ['opened', 'in_review'])
            ->get()
            ->each(function (DealDispute $dispute) use ($resolvedBy, $resolution, $arbiterVerdict): void {
                $dispute->status = 'closed';
                $dispute->resolved_by = $resolvedBy;
                $dispute->resolution = $resolution;
                if ($arbiterVerdict !== null && $arbiterVerdict !== '') {
                    $dispute->arbiter_verdict = $arbiterVerdict;
                }
                $dispute->resolved_at = now();
                $dispute->closed_at = now();
                $dispute->last_activity_at = now();
                $dispute->save();
            });
    }

    /**
     * Subject line for "deal message" email. Single fixed format (no localization on server).
     * MUST include lot number (id) so the client can parse it when opening from notification (e.g. IDLE).
     */
    private function getDealMessageSubject(ExchangeLot $lot): string
    {
        return sprintf('Deal message — Lot #%s', (string) $lot->id);
    }

    /**
     * Format currency code for display in emails and UI: e.g. usdt_tron → USDT (Tron).
     */
    private function formatCurrencyForDisplay(string $code): string
    {
        if ($code === '') {
            return $code;
        }
        if (str_contains($code, '_')) {
            $parts = explode('_', $code, 2);
            $token = strtoupper($parts[0]);
            $network = ucfirst(strtolower($parts[1]));

            return "{$token} ({$network})";
        }

        return strtoupper($code);
    }

    /** AML lot flag: null (not specified) / true (check required) / false (check disabled). */
    private function normalizeNullableAmlFlag(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed !== null) {
            return (bool) $parsed;
        }

        if (is_numeric($value)) {
            return ((int) $value) !== 0;
        }

        return null;
    }

    /**
     * Email body: deal summary (same info as on Deal screen) in buyer's language, then buyer's message.
     * Labels localized by buyer_locale; no seller language in body.
     */
    private function getDealMessageBody(ExchangeLot $lot, string $buyerMessage, ?float $selectedAmount): string
    {
        $strings = $this->getDealEmailStrings();

        $fromSym = $this->formatCurrencyForDisplay($lot->from_currency ?? '');
        $toSym = $this->formatCurrencyForDisplay($lot->to_currency ?? '');
        $buyAmounts = $lot->buy_amounts ?? [];
        $sellAmount = (float) $lot->sell_amount;
        $rate = isset($lot->rate) && (float) $lot->rate > 0
            ? (float) $lot->rate
            : ($sellAmount > 0 && count($buyAmounts) === 1 ? $buyAmounts[0] / $sellAmount : 0);
        $minAmount = (float) $lot->buy_amount_min;
        $maxAmount = (float) $lot->buy_amount_max;
        $aml = $lot->aml;
        $amlLabel = $strings['aml'];
        $amlRequired = $strings['aml_required'];
        $amlNotRequired = $strings['aml_not_required'];
        $amlText = "{$amlLabel}: ".((bool) $aml ? $amlRequired : $amlNotRequired);

        $lines = [
            $strings['summary_title'],
            '',
            $strings['pair'].': '.$fromSym.' → '.$toSym,
            $strings['pay_range'].': '.sprintf('%.2f', $minAmount).'–'.sprintf('%.2f', $maxAmount).' '.$toSym,
        ];
        if (count($buyAmounts) > 1 && $rate > 0) {
            $lines[] = $strings['rate'].': 1 '.$fromSym.' = '.$rate.' '.$toSym;
            if ($selectedAmount !== null && $selectedAmount > 0) {
                $receiveAmount = $selectedAmount / $rate;
                $totalStr = $maxAmount >= 1 && $maxAmount == (int) $maxAmount ? sprintf('%.0f', $maxAmount) : sprintf('%.2f', $maxAmount);
                $payStr = $selectedAmount >= 1 && $selectedAmount == (int) $selectedAmount ? sprintf('%.0f', $selectedAmount) : sprintf('%.2f', $selectedAmount);
                $receiveStr = $receiveAmount >= 1 && $receiveAmount == (int) $receiveAmount ? sprintf('%.0f', $receiveAmount) : rtrim(rtrim(sprintf('%.6f', $receiveAmount), '0'), '.');
                $lines[] = sprintf($strings['for_amount_you_get'], $payStr, $toSym, $totalStr, $toSym, $receiveStr, $fromSym);
            }
        }
        $lines[] = $amlText;
        $lines[] = $strings['lot_id'].': '.$lot->id;
        if ($lot->relationLoaded('banks') && $lot->banks->isNotEmpty()) {
            $bankNames = $lot->banks->map(function (Bank $b) {
                $swift = $b->swift_code ? " ({$b->swift_code})" : '';

                return $b->name.$swift;
            })->values()->all();
            $lines[] = $strings['accept_from_banks'].': '.implode(', ', $bankNames);
        }
        $lines[] = '';
        $lines[] = $strings['buyer_message'];
        $lines[] = $buyerMessage;

        return implode("\n", $lines);
    }

    /**
     * Strings for deal summary email. English only (no localization on server).
     * Used by getDealMessageBody if needed. Client is responsible for localized content.
     *
     * @return array<string, string>
     */
    private function getDealEmailStrings(): array
    {
        return [
            'summary_title' => 'Deal summary (buyer\'s view)',
            'pair' => 'Pair',
            'pay_range' => 'Pay range',
            'rate' => 'Rate',
            'for_amount_you_get' => 'For %s %s (of %s %s): you get %s %s',
            'aml' => 'AML',
            'aml_required' => 'wallet check required',
            'aml_not_required' => 'wallet check not required',
            'lot_id' => 'Lot ID',
            'accept_from_banks' => 'Accept from banks',
            'buyer_message' => 'Buyer\'s message:',
        ];
    }

    /**
     * Write a deal chat message (mail-as-event: when we send email we persist to chat table).
     */
    private function insertDealChatMessage(
        ExchangeLot $lot,
        string $senderSide,
        ?string $fromEmail,
        ?string $toEmail,
        string $body,
        ?string $dealTag
    ): bool {
        try {
            // Enforce max message length on server side.
            $maxLen = (int) config('deal.max_message_length', 1000);
            if ($maxLen > 0 && mb_strlen($body) > $maxLen) {
                $body = mb_substr($body, 0, $maxLen);
            }

            DealChatMessage::create([
                'exchange_lot_id' => $lot->id,
                'sender_side' => $senderSide,
                'from_email' => $fromEmail,
                'to_email' => $toEmail,
                'body' => $body,
                'deal_tag' => $dealTag,
            ]);

            // Keep only last N messages per deal to avoid unbounded growth.
            $maxMessages = (int) config('deal.max_messages_per_deal', 1000);
            if ($maxMessages > 0) {
                $total = DealChatMessage::where('exchange_lot_id', $lot->id)->count();
                if ($total > $maxMessages) {
                    $excess = $total - $maxMessages;
                    $idsToDelete = DealChatMessage::where('exchange_lot_id', $lot->id)
                        ->orderBy('id')
                        ->limit($excess)
                        ->pluck('id')
                        ->all();
                    if (! empty($idsToDelete)) {
                        DealChatMessage::whereIn('id', $idsToDelete)->delete();
                    }
                }
            }

            $this->dealWebPushNotifier->notifyDealChatRecipient($lot, $senderSide);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] insertDealChatMessage failed', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * New buyer session on a lot that had no active buyer: wipe prior deal chat and Maildir files
     * so DealChatTagSequenceValidator / client stage reducer do not see tags from an old deal.
     */
    private function resetLotDealChatAndMailboxesForNewDeal(ExchangeLot $lot, ?string $newBuyerMailboxEmail): void
    {
        $deletedChat = DealChatMessage::query()->where('exchange_lot_id', $lot->id)->delete();
        Log::info('[exchange-lots] start_deal: cleared prior deal chat for new session', [
            'lot_id' => $lot->id,
            'deleted_rows' => $deletedChat,
        ]);

        $this->closeOpenDisputesForLot(
            lot: $lot,
            resolvedBy: 'system',
            resolution: 'Superseded by a new buyer deal session.'
        );

        $currency = self::sellerMailboxCryptoCode($lot);
        if (! empty($lot->wallet_address)) {
            $this->lotMailboxService->cleanupMailbox((string) $lot->wallet_address, $currency);
        }
        $sellerLotMailbox = trim((string) ($lot->seller_lot_email ?? ''));
        if ($sellerLotMailbox !== '') {
            $this->lotMailboxService->cleanupMailboxByEmail($sellerLotMailbox);
        }
        $buyerMailbox = trim((string) ($newBuyerMailboxEmail ?? ''));
        if ($buyerMailbox !== '') {
            $this->lotMailboxService->cleanupMailboxByEmail($buyerMailbox);
        }
    }

    /**
     * After a terminal completion tag is accepted, mark sales completed, release Core reservations,
     * and clear buyer_lot_email — only when the tag row is persisted in deal_chat_messages.
     *
     * @param  array<string, mixed>|null  $customDealTagData
     */
    private function finalizeAmicableDealCompletionIfNeeded(
        ExchangeLot $lot,
        Request $request,
        ?array $customDealTagData,
        bool $chatPersisted
    ): ?JsonResponse {
        if (! is_array($customDealTagData)) {
            return null;
        }
        $isBuyerCompletion = DealSaleTransactionCompletion::dealTagIsBuyerCompletedForLot($customDealTagData, $lot);
        $isSellerTimeoutCompletion = DealSaleTransactionCompletion::dealTagIsSellerTimeoutCompletionForLot($customDealTagData, $lot);
        if (! $isBuyerCompletion && ! $isSellerTimeoutCompletion) {
            return null;
        }

        $lot->refresh();
        if (! $chatPersisted || ! $this->exchangeLotHasCompletedDealTagInChat($lot)) {
            Log::error('[exchange-lots] amicable completion aborted: terminal deal_tag not in chat history', [
                'lot_id' => $lot->id,
                'chat_persisted' => $chatPersisted,
                'tag_type' => $customDealTagData['t'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Deal completion could not be saved to chat history. Reservations were not finalized on the server.',
                'error_code' => 'deal_chat_persist_failed',
            ], 500);
        }

        $completedCount = DealSaleTransactionCompletion::markPendingSaleTransactionsCompleted($lot);
        if ($isBuyerCompletion) {
            Log::info('[exchange-lots] buyer completed deal — sale transactions marked completed', [
                'lot_id' => $lot->id,
                'updated_rows' => $completedCount,
            ]);
            $this->tryDeleteCoreReservationAfterBuyerAmicableCompletion($lot, $request, 'deal_completed_buyer');
        } else {
            Log::info('[exchange-lots] seller timeout deal completion — sale transactions marked completed', [
                'lot_id' => $lot->id,
                'updated_rows' => $completedCount,
            ]);
            $this->tryDeleteCoreReservationAfterSellerAmicableCompletion($lot, $request, 'deal_completed_seller_timeout');
        }
        DealTerminalLotClosure::releaseLotBuyerBinding($lot);

        return null;
    }

    /**
     * Decode custom deal tag JSON payload from client.
     *
     * @return array<string, mixed>|null
     */
    private function decodeDealTagFromRaw(string $raw): ?array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @return array{lot_id: string, node_key: string}|null
     */
    private function sellerOpenDealQueryParams(ExchangeLot $lot): ?array
    {
        $lot->loadMissing('node');

        return $this->openDealQueryParamsFromInputs(
            (string) $lot->id,
            is_string($lot->node?->domain) ? $lot->node->domain : null
        );
    }

    private function normalizeOpenDealNodeKey(?string $domain): ?string
    {
        if (! is_string($domain) || trim($domain) === '') {
            return null;
        }
        $nodeKey = strtolower(trim($domain));
        $nodeKey = (string) preg_replace('/^api\./i', '', $nodeKey);

        return $nodeKey !== '' ? $nodeKey : null;
    }

    /**
     * @return array{lot_id: string, node_key: string}|null
     */
    private function openDealQueryParamsFromInputs(?string $lotId, ?string $nodeDomain): ?array
    {
        if (! is_string($lotId) || trim($lotId) === '') {
            return null;
        }
        $nodeKey = $this->normalizeOpenDealNodeKey($nodeDomain);
        if ($nodeKey === null) {
            return null;
        }

        return [
            'lot_id' => trim($lotId),
            'node_key' => $nodeKey,
        ];
    }

    /**
     * @return array{clientWebUrl: ?string, appLandingUrl: ?string, appIntentUrl: ?string, appUrl: ?string}
     */
    private function buildOpenDealEmailLinks(?string $clientWebOrigin, ?array $params): array
    {
        $empty = [
            'clientWebUrl' => null,
            'appLandingUrl' => null,
            'appIntentUrl' => null,
            'appUrl' => null,
        ];
        if ($params === null) {
            return $empty;
        }
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $apiBase = app()->runningInConsole()
            ? null
            : rtrim(request()->getSchemeAndHttpHost(), '/');
        $origin = ClientWebOrigin::resolve(
            $clientWebOrigin,
            $apiBase,
            app()->runningInConsole() ? null : request()->header('Origin')
        );
        $clientWebUrl = $origin !== null ? $origin.'/open-deal?'.$query : null;
        $appLandingUrl = null;
        if ($apiBase !== null && $apiBase !== '') {
            $base = PublicHttpsUrl::upgradeMailBaseToHttps($apiBase);
            if ($base !== '') {
                $appLandingUrl = $base.'/open-deal?'.$query;
            }
        }
        $appUrl = DealSellerOpenLinks::appDeepLink($params);
        $appIntentUrl = DealSellerOpenLinks::androidIntentUri($params);

        return [
            'clientWebUrl' => $clientWebUrl,
            'appLandingUrl' => $appLandingUrl,
            'appIntentUrl' => $appIntentUrl,
            'appUrl' => $appUrl,
        ];
    }

    /**
     * HTTPS link on the web client origin (from client `client_web_origin`), path `/open-deal?…`.
     */
    private function buildClientWebOpenDealUrl(ExchangeLot $lot, ?string $clientWebOrigin): ?string
    {
        return $this->buildOpenDealEmailLinks($clientWebOrigin, $this->sellerOpenDealQueryParams($lot))['clientWebUrl'];
    }

    private function appendOpenDealPlainTextLinks(
        string $body,
        ?string $clientWebUrl,
        ?string $appIntentUrl,
        ?string $appLandingUrl,
        ?string $appUrl
    ): string {
        if ($appLandingUrl !== null) {
            $body .= "\n\nOpen in XBoard app: ".$appLandingUrl;
        }
        if ($clientWebUrl !== null) {
            $body .= "\n\nOpen in web browser: ".$clientWebUrl;
        }
        if ($appUrl !== null) {
            $body .= "\n\nApp link (copy if needed): ".$appUrl;
        }

        return $body;
    }

    /**
     * HTTPS link for email: same host/scheme as this API request (no APP_URL / env base).
     */
    private function buildSellerOpenDealWebUrl(ExchangeLot $lot): ?string
    {
        return $this->buildOpenDealEmailLinks(null, $this->sellerOpenDealQueryParams($lot))['appLandingUrl'];
    }

    /**
     * Deep link for the Android app: opens the same deal flow as tapping the local "new message" notification
     * (lot_id + node_key). Only added to seller_notification_email body, not to the lot mailbox copy.
     */
    private function buildSellerOpenDealAppLink(ExchangeLot $lot): ?string
    {
        return $this->buildOpenDealEmailLinks(null, $this->sellerOpenDealQueryParams($lot))['appUrl'];
    }

    /**
     * HTML + plain text for seller_notification_email: web client + API app landing + optional app scheme.
     */
    private function buildSellerExternalNotificationHtml(
        string $messageBody,
        ?string $webUrl,
        ?string $clientWebUrl,
        ?string $appIntentUrl,
        ?string $appUrl
    ): string {
        return view('mail.deal_external_notification', [
            'body' => $messageBody,
            'webUrl' => $webUrl,
            'clientWebUrl' => $clientWebUrl,
            'appIntentUrl' => $appIntentUrl,
            'appUrl' => $appUrl,
        ])->render();
    }

    /**
     * Multipart email (text + HTML) so clients show a tappable link in HTML mode.
     */
    private function sendDealEmailHtmlMultipart(
        string $to,
        string $subject,
        string $fromAddress,
        string $fromName,
        string $plainBody,
        string $htmlBody,
        ?string $tagJson
    ): void {
        Mail::send([], [], function ($message) use ($to, $subject, $fromAddress, $fromName, $plainBody, $htmlBody, $tagJson) {
            $message->to($to);
            $message->subject($subject);
            $message->from($fromAddress, $fromName);
            $message->text($plainBody);
            $message->html($htmlBody);
            if ($tagJson !== null) {
                $message->getHeaders()->addTextHeader('X-XBoard-Deal-Tag', $tagJson);
            }
            HighDeliveryPriority::apply($message);
        });
    }

    /**
     * Send deal email as plain text.
     * Structured payload is delivered via X-XBoard-Deal-Tag and rendered by client chat.
     */
    private function sendDealEmail(
        string $to,
        string $subject,
        string $fromAddress,
        string $fromName,
        string $body,
        ?string $tagJson
    ): void {
        Mail::raw($body, function ($message) use ($to, $subject, $fromAddress, $fromName, $tagJson) {
            $message->to($to);
            $message->subject($subject);
            $message->from($fromAddress, $fromName);
            if ($tagJson !== null) {
                $message->getHeaders()->addTextHeader('X-XBoard-Deal-Tag', $tagJson);
            }
            HighDeliveryPriority::apply($message);
        });
    }

    /**
     * Increment per-recipient daily counter for deal external notification emails. Returns false when limit is reached.
     *
     * Applies to: send-test-notification-email and start_deal external notifications (seller_notification_email,
     * buyer_notification_email). Default cap: 30 per (email, calendar day); mail.test_email_daily_limit / MAIL_TEST_EMAIL_DAILY_LIMIT.
     */
    private function incrementEmailTestCounter(string $email): bool
    {
        $limit = (int) config('mail.test_email_daily_limit', 30);
        if ($limit <= 0) {
            // 0 or negative means "no limit" (do not block sends).
            return true;
        }

        $normalized = strtolower(trim($email));
        if ($normalized === '') {
            return false;
        }

        $today = now()->toDateString();

        return DB::transaction(function () use ($normalized, $today, $limit): bool {
            $row = DB::table('email_test_counters')
                ->where('email', $normalized)
                ->where('day', $today)
                ->lockForUpdate()
                ->first();

            if ($row !== null && (int) $row->sent_count >= $limit) {
                return false;
            }

            if ($row === null) {
                DB::table('email_test_counters')->insert([
                    'email' => $normalized,
                    'day' => $today,
                    'sent_count' => 1,
                    'last_sent_at' => now(),
                ]);
            } else {
                DB::table('email_test_counters')
                    ->where('id', $row->id)
                    ->update([
                        'sent_count' => (int) $row->sent_count + 1,
                        'last_sent_at' => now(),
                    ]);
            }

            return true;
        });
    }

    /**
     * Check if email was verified via escrow token creation flow.
     */
    private function isVerifiedEmail(string $email): bool
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '') {
            return false;
        }

        return \DB::table('verified_emails')
            ->where('email', $normalized)
            ->exists();
    }

    /**
     * For deal-tag messages that carry bank-receipt `receipt_links` (buyer
     * `receipt_confirmation` for the first leg or seller `seller_transfer_tx`
     * for the second leg in reverse-flow deals), move links from tmp to
     * permanent storage and rewrite them in the deal tag payload before
     * sending email/chat.
     *
     * @param  array<string, mixed>  $dealTagData
     * @param  array<string, string>  $replacements  old_link => new_link (for body text rewrite)
     * @return array<string, mixed>
     */
    private function promoteReceiptLinksInDealTag(array $dealTagData, array &$replacements = []): array
    {
        $tagType = $dealTagData['t'] ?? null;
        if ($tagType !== 'receipt_confirmation' && $tagType !== 'seller_transfer_tx') {
            return $dealTagData;
        }

        $rawLinks = $dealTagData['receipt_links'] ?? null;
        if (! is_array($rawLinks) || count($rawLinks) === 0) {
            return $dealTagData;
        }

        $normalizedLinks = [];
        foreach ($rawLinks as $item) {
            if (! is_array($item)) {
                continue;
            }
            $filename = isset($item['filename']) ? trim((string) $item['filename']) : '';
            $link = isset($item['link']) ? trim((string) $item['link']) : '';
            if ($link === '') {
                continue;
            }
            $newLink = ReceiptAttachmentStorage::moveTmpToPermanentAndReturnNewLink($link);
            if ($newLink !== $link) {
                $replacements[$link] = $newLink;
            }
            $sha256 = $this->receiptFileSha256FromLink($newLink);
            $normalizedLinks[] = [
                'filename' => $filename,
                'link' => $newLink,
                'sha256' => $sha256,
            ];
        }

        if (count($normalizedLinks) > 0) {
            $dealTagData['receipt_links'] = $normalizedLinks;
        }

        return $dealTagData;
    }

    /**
     * Persist bank-receipt links into the latest related transaction.
     * Accepts both:
     * - buyer `receipt_confirmation.receipt_links` (first leg — buyer paid bank to seller);
     * - seller `seller_transfer_tx.receipt_links` (reverse flow second leg — seller paid bank to buyer).
     *
     * @param  array<string, mixed>|null  $dealTagData
     */
    private function storeReceiptLinksInLatestTransaction(ExchangeLot $lot, ?array $dealTagData): void
    {
        $tagType = is_array($dealTagData) ? ($dealTagData['t'] ?? null) : null;
        if (! is_array($dealTagData) || ($tagType !== 'receipt_confirmation' && $tagType !== 'seller_transfer_tx')) {
            return;
        }

        $rawLinks = $dealTagData['receipt_links'] ?? null;
        if (! is_array($rawLinks) || count($rawLinks) === 0) {
            return;
        }

        $normalizedLinks = [];
        foreach ($rawLinks as $item) {
            if (! is_array($item)) {
                continue;
            }
            $filename = isset($item['filename']) ? trim((string) $item['filename']) : '';
            $link = isset($item['link']) ? trim((string) $item['link']) : '';
            if ($link === '') {
                continue;
            }
            // Move file from tmp to permanent if needed; store the permanent link in receipt_links_json
            $link = ReceiptAttachmentStorage::moveTmpToPermanentAndReturnNewLink($link);
            $sha256 = $this->receiptFileSha256FromLink($link);
            $normalizedLinks[] = [
                'filename' => $filename,
                'link' => $link,
                'sha256' => $sha256,
            ];
        }

        if (count($normalizedLinks) === 0) {
            return;
        }

        try {
            $transaction = $lot->latestReceiptTransaction();
            if ($transaction === null) {
                Log::warning('[exchange-lots] storeReceiptLinksInLatestTransaction: no transaction found', [
                    'lot_id' => $lot->id,
                    'buyer_lot_email' => $lot->buyer_lot_email,
                    'links_count' => count($normalizedLinks),
                ]);

                return;
            }

            $previousLinks = $transaction->receipt_links_json;
            $transaction->receipt_links_json = $normalizedLinks;
            $transaction->save();
            if (is_array($previousLinks) && count($previousLinks) > 0) {
                ReceiptAttachmentStorage::deleteFilesRemovedFromReceiptLinks($previousLinks, $normalizedLinks);
            }
            $senderSide = $tagType === 'receipt_confirmation' ? 'buyer' : 'seller';
            $this->rememberReceiptHashes(
                lot: $lot,
                senderSide: $senderSide,
                tagType: (string) $tagType,
                receiptLinks: $normalizedLinks
            );
            Log::info('[exchange-lots] storeReceiptLinksInLatestTransaction: saved', [
                'lot_id' => $lot->id,
                'transaction_id' => $transaction->id,
                'links_count' => count($normalizedLinks),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] storeReceiptLinksInLatestTransaction failed', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sandbox-only synthetic RU bank: send PDF receipt to the deal participant mailbox
     * (buyer first, fallback to seller). This lets the user verify the receipt from their own inbox.
     */
    private function sendSandboxVirtualBankReceiptEmail(ExchangeLot $lot, Transaction $transaction, float $amount): void
    {
        if (! SandboxVirtualBankService::isSandboxApiHost(request()->getHost())) {
            return;
        }
        $setBy = strtolower(trim((string) ($transaction->payment_details_set_by ?? '')));
        // Receipt goes to the counterparty for whom payment details are shown.
        $to = match ($setBy) {
            'seller' => trim((string) ($lot->buyer_notification_email ?? '')),
            'buyer' => trim((string) ($lot->seller_notification_email ?? '')),
            default => '',
        };
        if ($to === null || $to === '') {
            Log::warning('[exchange-lots] sendSandboxVirtualBankReceiptEmail: recipient by payment_details_set_by is empty', [
                'lot_id' => $lot->id,
                'transaction_id' => $transaction->id,
                'payment_details_set_by' => $transaction->payment_details_set_by,
                'buyer_notification_email_present' => ! empty($lot->buyer_notification_email),
                'seller_notification_email_present' => ! empty($lot->seller_notification_email),
            ]);

            return;
        }

        $fiatCode = 'FIAT';
        if ($lot->toFiatCurrency) {
            $fiatCode = (string) $lot->toFiatCurrency->code;
        } elseif ($lot->fromFiatCurrency) {
            $fiatCode = (string) $lot->fromFiatCurrency->code;
        }

        $paymentAtUtc = ($transaction->created_at ?? now())->clone()->utc();
        $generatedAtUtc = now()->utc();
        $operationNo = 'SB'.$paymentAtUtc->format('dmy').sprintf('%06d', (int) $transaction->id);
        $authCode = strtoupper(substr(hash('sha256', (string) $transaction->tx_hash), 0, 6));
        $terminalCode = str_pad((string) (($lot->id * 97 + $transaction->id * 13) % 1_000_000), 6, '0', STR_PAD_LEFT);

        $pdf = SandboxTestReceiptPdf::build([
            'Transfer receipt (sandbox test bank)',
            'Generated at: '.$generatedAtUtc->format('d.m.Y H:i:s').' UTC',
            'Payment date/time: '.$paymentAtUtc->format('d.m.Y H:i:s').' UTC',
            'Beneficiary bank: '.(string) config('sandbox_virtual_bank.name_en'),
            'Payment amount: '.number_format($amount, 2, '.', ' ').' '.$fiatCode,
            'Fee: 0.00 '.$fiatCode,
            'Authorization code: '.$authCode,
            'Terminal code: '.$terminalCode,
            'Bank operation number: '.$operationNo,
            'Reference: SB-'.$lot->id.'-'.$transaction->id,
        ]);

        $headerName = (string) config('sandbox_virtual_bank.receipt_mime_header_name');
        $headerVal = (string) config('sandbox_virtual_bank.receipt_mime_header_value');

        try {
            Log::debug('[exchange-lots] sendSandboxVirtualBankReceiptEmail: preparing send', [
                'lot_id' => $lot->id,
                'transaction_id' => $transaction->id,
                'payment_details_set_by' => $transaction->payment_details_set_by,
                'to' => $to,
                'host' => request()->getHost(),
            ]);
            Mail::send([], [], function ($message) use ($to, $pdf, $lot, $transaction, $headerName, $headerVal): void {
                $message->to($to);
                $message->subject('Sandbox test receipt PDF — Lot #'.$lot->id);
                $message->from(config('mail.from.address', 'noreply@xdexboard.com'), config('mail.from.name', 'XBoard'));
                $message->text('Synthetic PDF receipt for sandbox virtual bank (Lot #'.$lot->id.', tx '.$transaction->id.').');
                $message->attachData($pdf, 'receipt-lot-'.$lot->id.'.pdf', ['mime' => 'application/pdf']);
                if ($headerName !== '' && $headerVal !== '') {
                    $message->getHeaders()->addTextHeader($headerName, $headerVal);
                }
                HighDeliveryPriority::apply($message);
            });
            Log::info('[exchange-lots] sendSandboxVirtualBankReceiptEmail: sent', [
                'lot_id' => $lot->id,
                'transaction_id' => $transaction->id,
                'payment_details_set_by' => $transaction->payment_details_set_by,
                'to' => $to,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] sendSandboxVirtualBankReceiptEmail: failed', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $dealTagData
     * @return array{
     *   deal_tag: array<string, mixed>,
     *   error: array{message:string,error_code:string,status?:int}|null
     * }
     */
    private function validateAndAttachReceiptChecksums(ExchangeLot $lot, string $senderSide, array $dealTagData): array
    {
        $tagType = trim((string) ($dealTagData['t'] ?? ''));
        if (! in_array($tagType, ['receipt_confirmation', 'seller_transfer_tx'], true)) {
            return ['deal_tag' => $dealTagData, 'error' => null];
        }
        $rawLinks = $dealTagData['receipt_links'] ?? null;
        if (! is_array($rawLinks) || count($rawLinks) === 0) {
            return ['deal_tag' => $dealTagData, 'error' => null];
        }

        $normalizedLinks = [];
        $hashes = [];
        foreach ($rawLinks as $item) {
            if (! is_array($item)) {
                continue;
            }
            $link = trim((string) ($item['link'] ?? ''));
            if ($link === '') {
                continue;
            }
            $sha256 = strtolower(trim((string) ($item['sha256'] ?? '')));
            if (! preg_match('/^[a-f0-9]{64}$/', $sha256)) {
                $sha256 = $this->receiptFileSha256FromLink($link) ?? '';
            }
            if ($sha256 !== '') {
                $hashes[] = $sha256;
            }
            $normalizedLinks[] = [
                'filename' => trim((string) ($item['filename'] ?? '')),
                'link' => $link,
                'sha256' => $sha256 !== '' ? $sha256 : null,
            ];
        }

        if (count($normalizedLinks) > 0) {
            $dealTagData['receipt_links'] = $normalizedLinks;
        }
        $hashes = array_values(array_unique(array_filter($hashes, static fn (string $v): bool => $v !== '')));
        if (count($hashes) > 0) {
            $dealTagData['receipt_sha256'] = $hashes;
        }

        if (count($hashes) === 0) {
            return ['deal_tag' => $dealTagData, 'error' => null];
        }

        if ($this->hasReceiptHashDuplicateRecorded($lot, $senderSide, $tagType, $hashes)) {
            $dealState = DealChatTagSequenceValidator::computeStateForLot($lot);
            $allowIdenticalResubmit =
                ($tagType === 'receipt_confirmation' && $senderSide === 'buyer'
                    && $dealState === DealChatTagSequenceValidator::STATE_WAIT_SELLER_CONFIRM)
                || ($tagType === 'seller_transfer_tx' && $senderSide === 'seller'
                    && $dealState === DealChatTagSequenceValidator::STATE_WAIT_BUYER_COMPLETE);
            if (! $allowIdenticalResubmit) {
                return [
                    'deal_tag' => $dealTagData,
                    'error' => [
                        'message' => 'This bank receipt was already sent for this deal. Re-sending identical receipt is blocked.',
                        'error_code' => 'duplicate_receipt_checksum',
                        'status' => 422,
                    ],
                ];
            }
        }

        return ['deal_tag' => $dealTagData, 'error' => null];
    }

    /**
     * @param  array<string, mixed>  $dealTagData
     * @return array{message:string,error_code:string,status?:int}|null
     */
    private function validateDisputeOpenGuard(ExchangeLot $lot, string $senderSide, array $dealTagData): ?array
    {
        $tagType = trim((string) ($dealTagData['t'] ?? ''));
        if (! DealChatTagSequenceValidator::isDisputeTag($tagType)) {
            return null;
        }

        $hasVerifiedCryptoProof = $this->hasVerifiedCryptoProofInChat($lot, $senderSide);
        $latestReceiptTx = $lot->latestReceiptTransaction();
        $bankHashes = $this->extractReceiptHashesFromLinks(
            is_array($latestReceiptTx?->receipt_links_json) ? $latestReceiptTx->receipt_links_json : []
        );
        $hasBankProof = count($bankHashes) > 0;
        if (! $hasVerifiedCryptoProof && ! $hasBankProof) {
            return [
                'message' => 'Dispute can be opened only after payment proof is provided (bank receipt or verified crypto tx id).',
                'error_code' => 'dispute_requires_payment_proof',
                'status' => 422,
            ];
        }

        $receiptTimeCheck = $this->validateReceiptTimeWindowForDispute($lot, $senderSide);
        if ($receiptTimeCheck !== null) {
            return $receiptTimeCheck;
        }

        return null;
    }

    /**
     * @param  list<string>  $hashes
     */
    private function hasReceiptHashDuplicateRecorded(ExchangeLot $lot, string $senderSide, string $tagType, array $hashes): bool
    {
        return DB::table('receipt_file_checksums')
            ->where('exchange_lot_id', $lot->id)
            ->where('sender_side', $senderSide)
            ->where('tag_type', $tagType)
            ->whereIn('sha256', $hashes)
            ->exists();
    }

    /**
     * @param  array<int, mixed>  $links
     * @return list<string>
     */
    private function extractReceiptHashesFromLinks(array $links): array
    {
        $hashes = [];
        foreach ($links as $item) {
            if (! is_array($item)) {
                continue;
            }
            $sha = strtolower(trim((string) ($item['sha256'] ?? '')));
            if (! preg_match('/^[a-f0-9]{64}$/', $sha)) {
                $link = trim((string) ($item['link'] ?? ''));
                if ($link !== '') {
                    $sha = $this->receiptFileSha256FromLink($link) ?? '';
                }
            }
            if ($sha !== '') {
                $hashes[] = $sha;
            }
        }

        return array_values(array_unique($hashes));
    }

    private function receiptFileSha256FromLink(string $link): ?string
    {
        $relativePath = ReceiptAttachmentStorage::linkToRelativePath($link);
        if ($relativePath === null || $relativePath === '') {
            return null;
        }
        $absolutePath = ReceiptAttachmentStorage::absolutePathIfExists($relativePath);
        if ($absolutePath === null) {
            return null;
        }
        try {
            $sha = hash_file('sha256', $absolutePath);
            return is_string($sha) && preg_match('/^[a-f0-9]{64}$/', $sha)
                ? strtolower($sha)
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, array{filename?:string,link?:string,sha256?:string|null}>  $receiptLinks
     */
    private function rememberReceiptHashes(ExchangeLot $lot, string $senderSide, string $tagType, array $receiptLinks): void
    {
        $hashes = $this->extractReceiptHashesFromLinks($receiptLinks);
        if (count($hashes) === 0) {
            return;
        }

        $now = now();
        $rows = array_map(static function (string $sha) use ($lot, $senderSide, $tagType, $now): array {
            return [
                'exchange_lot_id' => $lot->id,
                'sender_side' => $senderSide,
                'tag_type' => $tagType,
                'sha256' => $sha,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $hashes);

        DB::table('receipt_file_checksums')->insertOrIgnore($rows);
    }

    private function hasVerifiedCryptoProofInChat(ExchangeLot $lot, string $senderSide): bool
    {
        $messages = DealChatMessage::query()
            ->where('exchange_lot_id', $lot->id)
            ->where('sender_side', $senderSide)
            ->whereNotNull('deal_tag')
            ->orderByDesc('id')
            ->limit(120)
            ->get(['deal_tag']);
        foreach ($messages as $message) {
            $raw = trim((string) ($message->deal_tag ?? ''));
            if ($raw === '') {
                continue;
            }
            $tag = json_decode($raw, true);
            if (! is_array($tag)) {
                continue;
            }
            $type = trim((string) ($tag['t'] ?? ''));
            if (! in_array($type, ['buyer_crypto_tx', 'seller_transfer_tx'], true)) {
                continue;
            }
            $checked = is_array($tag['core_tx_check'] ?? null) ? $tag['core_tx_check'] : [];
            if ((bool) ($checked['verified'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{message:string,error_code:string,status?:int}|null
     */
    private function validateReceiptTimeWindowForDispute(ExchangeLot $lot, string $senderSide): ?array
    {
        $proofTag = $this->latestPaymentProofTagForSender($lot, $senderSide);
        if ($proofTag === null) {
            return null;
        }
        $receiptPaidAtUtc = $this->extractReceiptPaidAtUtcFromTag($proofTag);
        if ($receiptPaidAtUtc === null) {
            return null;
        }

        $dealStartedAt = $this->dealStartedAtUtc($lot);
        if ($dealStartedAt === null) {
            return null;
        }

        $graceHours = max(0, (int) config('deal.bank_receipt_dispute_time_grace_hours', 12));
        $oldestAllowed = $dealStartedAt->copy()->subHours($graceHours);
        if ($receiptPaidAtUtc->lt($oldestAllowed)) {
            return [
                'message' => 'Receipt payment time is too far before deal start (outside allowed UTC grace window).',
                'error_code' => 'bank_receipt_time_too_old_for_deal',
                'status' => 422,
            ];
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestPaymentProofTagForSender(ExchangeLot $lot, string $senderSide): ?array
    {
        $messages = DealChatMessage::query()
            ->where('exchange_lot_id', $lot->id)
            ->where('sender_side', $senderSide)
            ->whereNotNull('deal_tag')
            ->orderByDesc('id')
            ->limit(120)
            ->get(['deal_tag']);
        foreach ($messages as $message) {
            $raw = trim((string) ($message->deal_tag ?? ''));
            if ($raw === '') {
                continue;
            }
            $tag = json_decode($raw, true);
            if (! is_array($tag)) {
                continue;
            }
            $type = trim((string) ($tag['t'] ?? ''));
            if (in_array($type, ['receipt_confirmation', 'seller_transfer_tx', 'buyer_crypto_tx'], true)) {
                return $tag;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $tag
     */
    private function extractReceiptPaidAtUtcFromTag(array $tag): ?\Carbon\CarbonImmutable
    {
        $utcFields = ['payment_time_utc', 'paid_at_utc', 'receipt_time_utc'];
        foreach ($utcFields as $field) {
            $raw = trim((string) ($tag[$field] ?? ''));
            if ($raw === '') {
                continue;
            }
            try {
                return \Carbon\CarbonImmutable::parse($raw)->utc();
            } catch (\Throwable) {
            }
        }

        $paymentRaw = trim((string) ($tag['payment_time_raw'] ?? $tag['payment_time'] ?? ''));
        if ($paymentRaw === '') {
            return null;
        }
        $timezone = trim((string) ($tag['bank_timezone'] ?? 'UTC'));
        if ($timezone === '') {
            $timezone = 'UTC';
        }
        try {
            return \Carbon\CarbonImmutable::parse($paymentRaw, $timezone)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function dealStartedAtUtc(ExchangeLot $lot): ?\Carbon\CarbonImmutable
    {
        $startTagRow = DealChatMessage::query()
            ->where('exchange_lot_id', $lot->id)
            ->whereNotNull('deal_tag')
            ->orderBy('id')
            ->get(['deal_tag', 'created_at']);
        foreach ($startTagRow as $row) {
            $raw = trim((string) ($row->deal_tag ?? ''));
            if ($raw === '') {
                continue;
            }
            $tag = json_decode($raw, true);
            if (! is_array($tag) || trim((string) ($tag['t'] ?? '')) !== 'start_deal') {
                continue;
            }
            try {
                return \Carbon\CarbonImmutable::parse((string) $row->created_at)->utc();
            } catch (\Throwable) {
                break;
            }
        }

        $tx = Transaction::query()
            ->where('exchange_lot_id', $lot->id)
            ->orderBy('id')
            ->first(['created_at']);
        if ($tx !== null && $tx->created_at !== null) {
            try {
                return \Carbon\CarbonImmutable::parse((string) $tx->created_at)->utc();
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function getCoreApiUrl(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (! preg_match('/^(sandbox\.|dev\.)/', $host)) {
            return rtrim((string) config('services.core_production.url'), '/');
        }

        return rtrim((string) config('services.core.url'), '/');
    }

    private function getCoreApiKey(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (! preg_match('/^(sandbox\.|dev\.)/', $host)) {
            return (string) config('services.core_production.key');
        }

        return (string) config('services.core.key');
    }

    /**
     * @param  array<string, mixed>  $dealTagData
     * @return array{
     *   deal_tag: array<string, mixed>,
     *   error: array{message:string,error_code:string,status?:int,verification?:array<string,mixed>}|null
     * }
     */
    private function verifyCryptoTxDealTagViaCore(ExchangeLot $lot, array $dealTagData): array
    {
        $tagType = trim((string) ($dealTagData['t'] ?? ''));
        if (! in_array($tagType, ['buyer_crypto_tx', 'seller_transfer_tx'], true)) {
            return ['deal_tag' => $dealTagData, 'error' => null];
        }

        // Bank / PDF second leg (crypto→fiat): seller_transfer_tx carries receipt_links only — no on-chain id for Core.
        if ($tagType === 'seller_transfer_tx') {
            $receiptLinks = $dealTagData['receipt_links'] ?? null;
            if (is_array($receiptLinks) && count($receiptLinks) > 0) {
                return ['deal_tag' => $dealTagData, 'error' => null];
            }
        }

        // seller_transfer_tx from the app uses tx_number; buyer_crypto_tx uses tx_ref / transaction_id.
        $txRef = trim((string) ($dealTagData['tx_ref'] ?? $dealTagData['transaction_id'] ?? $dealTagData['tx_number'] ?? ''));
        if ($txRef === '') {
            return [
                'deal_tag' => $dealTagData,
                'error' => [
                    'message' => 'transaction id (tx_ref) is required for crypto transfer proof.',
                    'error_code' => 'crypto_tx_id_required',
                ],
            ];
        }

        try {
            $cryptoType = self::coreCryptoTypeForSellerEscrow($lot);
        } catch (\InvalidArgumentException $e) {
            return [
                'deal_tag' => $dealTagData,
                'error' => [
                    'message' => $e->getMessage(),
                    'error_code' => 'invalid_lot_crypto',
                ],
            ];
        }

        $payload = [
            'transaction_id' => $txRef,
            'crypto_type' => $cryptoType,
            'min_confirmations' => 0,
            'strict_checks' => true,
        ];
        $expectedAddress = $this->expectedAddressForCoreTxCheck($lot, $tagType);
        if ($expectedAddress !== null && $expectedAddress !== '') {
            $payload['expected_address'] = $expectedAddress;
        }
        $expectedAmount = $this->expectedAmountForCoreTxCheck($lot, $tagType);
        if ($expectedAmount !== null && $expectedAmount > 0) {
            $payload['expected_amount'] = $expectedAmount;
        }

        $coreUrl = rtrim($this->getCoreApiUrl(), '/');
        $endpoint = $coreUrl.'/api/escrow-tokens/verify-external-payment';
        $req = Http::timeout((int) config('services.core.timeout', 10));
        $coreKey = $this->getCoreApiKey();
        if ($coreKey !== '') {
            $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
        }

        try {
            $resp = $req->asJson()->post($endpoint, $payload);
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] verifyCryptoTxDealTagViaCore: transport exception', [
                'lot_id' => $lot->id,
                'tag_type' => $tagType,
                'tx_ref' => $txRef,
                'error' => $e->getMessage(),
            ]);
            return [
                'deal_tag' => $dealTagData,
                'error' => [
                    'message' => 'Failed to verify crypto transaction id via Core.',
                    'error_code' => 'core_crypto_tx_verify_transport_failed',
                    'status' => 502,
                ],
            ];
        }

        $body = $resp->json();
        $data = is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : [];
        if (! $resp->successful()) {
            return [
                'deal_tag' => $dealTagData,
                'error' => [
                    'message' => is_array($body) ? (string) ($body['message'] ?? 'Core tx verification failed.') : 'Core tx verification failed.',
                    'error_code' => is_array($body) ? (string) ($body['error_code'] ?? 'core_crypto_tx_verify_failed') : 'core_crypto_tx_verify_failed',
                    'status' => $resp->status() >= 500 ? 502 : 422,
                ],
            ];
        }

        $verified = (bool) ($data['verified'] ?? false);
        $verification = [
            'source' => 'core_verify_external_payment',
            'checked_at_utc' => now()->utc()->toIso8601String(),
            'verified' => $verified,
            'status' => $data['status'] ?? null,
            'is_confirmed' => $data['is_confirmed'] ?? null,
            'confirmations' => $data['confirmations'] ?? null,
            'checks' => is_array($data['checks'] ?? null) ? $data['checks'] : [],
        ];
        $dealTagData['core_tx_check'] = $verification;

        if (! $verified) {
            $amountMatches = $verification['checks']['amount_matches'] ?? null;
            if ($amountMatches === false) {
                return [
                    'deal_tag' => $dealTagData,
                    'error' => [
                        'message' => 'Crypto payment amount is lower than required for this deal.',
                        'error_code' => 'crypto_tx_amount_too_low',
                        'status' => 422,
                        'verification' => $verification,
                    ],
                ];
            }
            return [
                'deal_tag' => $dealTagData,
                'error' => [
                    'message' => 'Crypto transaction id is not verified yet. Check tx id / confirmations and try again.',
                    'error_code' => 'crypto_tx_not_verified',
                    'status' => 422,
                    'verification' => $verification,
                ],
            ];
        }

        return ['deal_tag' => $dealTagData, 'error' => null];
    }

    private function expectedAddressForCoreTxCheck(ExchangeLot $lot, string $tagType): ?string
    {
        if ($tagType === 'buyer_crypto_tx') {
            $tx = Transaction::query()
                ->where('exchange_lot_id', $lot->id)
                ->where('payment_details_set_by', 'seller')
                ->whereNotNull('crypto_address')
                ->orderByDesc('id')
                ->first();
            $addr = trim((string) ($tx?->crypto_address ?? ''));
            return $addr !== '' ? $addr : null;
        }
        if ($tagType === 'seller_transfer_tx') {
            $rows = DealChatMessage::query()
                ->where('exchange_lot_id', $lot->id)
                ->orderByDesc('id')
                ->limit(60)
                ->pluck('deal_tag')
                ->all();
            foreach ($rows as $raw) {
                if (! is_string($raw) || trim($raw) === '') {
                    continue;
                }
                $obj = json_decode($raw, true);
                if (! is_array($obj) || (($obj['t'] ?? '') !== 'buyer_destination_details')) {
                    continue;
                }
                $addr = trim((string) ($obj['address'] ?? ''));
                if ($addr !== '') {
                    return $addr;
                }
            }
        }
        return null;
    }

    private function expectedAmountForCoreTxCheck(ExchangeLot $lot, string $tagType): ?float
    {
        // Primary source for partial deals: current receipt-related transaction row.
        $receiptTx = $lot->latestReceiptTransaction();
        $receiptAmount = is_numeric($receiptTx?->amount) ? (float) $receiptTx->amount : null;
        if ($receiptAmount !== null && $receiptAmount > 0) {
            return $receiptAmount;
        }

        if ($tagType === 'buyer_crypto_tx') {
            $tx = Transaction::query()
                ->where('exchange_lot_id', $lot->id)
                ->where('payment_details_set_by', 'seller')
                ->orderByDesc('id')
                ->first(['amount']);
            $amount = is_numeric($tx?->amount) ? (float) $tx->amount : null;
            if ($amount !== null && $amount > 0) {
                return $amount;
            }
        }

        if ($tagType === 'seller_transfer_tx') {
            $tx = Transaction::query()
                ->where('exchange_lot_id', $lot->id)
                ->where('payment_details_set_by', 'buyer')
                ->orderByDesc('id')
                ->first(['amount']);
            $amount = is_numeric($tx?->amount) ? (float) $tx->amount : null;
            if ($amount !== null && $amount > 0) {
                return $amount;
            }

            // Fallback only when explicit transaction row is not available.
            $selected = is_numeric($lot->buyer_selected_amount) ? (float) $lot->buyer_selected_amount : null;
            if ($selected !== null && $selected > 0) {
                return $selected;
            }
        }

        return null;
    }

    /**
     * Strip verification-shaped keys from client deal_tag.
     * Only server code is allowed to write `core_tx_check`.
     *
     * @param  array<string, mixed>  $dealTagData
     * @return array<string, mixed>
     */
    private function sanitizeIncomingCustomDealTag(array $dealTagData): array
    {
        unset(
            $dealTagData['core_tx_check'],
            $dealTagData['core_tx_check_signature'],
            $dealTagData['core_tx_check_raw']
        );

        return $dealTagData;
    }
}
