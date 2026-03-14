<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\Country;
use App\Models\CryptoCurrency;
use App\Models\ExchangeLot;
use App\Models\FiatCurrency;
use App\Models\Transaction;
use App\Services\EscrowTokenValidationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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
        private \App\Services\LotMailboxService $lotMailboxService
    ) {}

    /**
     * @OA\Get(
     *     path="/exchange-lots",
     *     summary="List exchange lots",
     *     tags={"Exchange Lots"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer")),
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
            'db_database' => config('database.connections.' . config('database.default') . '.database'),
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
            $bank = Bank::where('swift_code', trim($request->bank_swift))->first();
            $recipientBankId = $bank?->id;
        }
        if ($recipientBankId === null && $request->filled('bank_id')) {
            $recipientBankId = (int) $request->bank_id;
        }
        if ($recipientBankId !== null) {
            $query->where('bank_id', $recipientBankId);
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
        // AML: when aml_max is set, only lots with AML filled and <= threshold (exclude lots with null AML)
        if ($request->filled('aml_max')) {
            $amlMax = (int) $request->aml_max;
            if ($amlMax >= 0 && $amlMax <= 100) {
                $query->whereNotNull('aml')->where('aml', '<=', $amlMax);
            }
        }
        $walletAddresses = $request->input('wallet_address') ?? $request->input('wallet_address[]');
        $hashes = [];
        if (!empty($walletAddresses)) {
            $addresses = is_array($walletAddresses) ? $walletAddresses : [$walletAddresses];
            $addresses = array_filter(array_map('trim', $addresses));
            foreach ($addresses as $a) {
                $decoded = json_decode($a, true);
                if (is_array($decoded) && !empty($decoded['token_hash'])) {
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
            if (!empty($hashes)) {
                $query->whereIn('wallet_hash', $hashes);
            }
        }

        // Public search: exclude lots that have a buyer in deal (buyer_lot_email set)
        if (empty($hashes)) {
            $query->where(function ($q) {
                $q->whereNull('buyer_lot_email')->orWhere('buyer_lot_email', '');
            });
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
        if (!empty($hashes)) {
            $responseLog['hash_prefixes'] = array_map(fn ($h) => substr($h, 0, 12) . '...', array_slice($hashes, 0, 5));
        }
        $responseLog['returned_ids'] = array_map(fn ($l) => $l->id, $items);
        $responseLog['returned_wallet_hashes'] = array_map(fn ($l) => substr($l->wallet_hash ?? '', 0, 12) . '...', $items);
        $responseLog['total_all_test_in_db'] = $totalAllTest;
        // When filter returns 0 but DB has lots — log their wallet_hashes for debugging
        if ($count === 0 && $totalAllTest > 0 && !empty($hashes)) {
            $allTestLots = ExchangeLot::when($request->has('is_test') && $request->is_test === 'true', fn ($q) => $q->test($testGroupHashForTotal))
                ->when($request->has('is_test') && $request->is_test !== 'true', fn ($q) => $q->main($mainGroupHashForTotal))
                ->get(['id', 'wallet_hash']);
            $responseLog['db_test_wallet_hashes'] = $allTestLots->map(fn ($l) => substr($l->wallet_hash ?? '', 0, 16) . '...')->all();
        }
        Log::info('[exchange-lots] RESPONSE', $responseLog);

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\ExchangeLotResource::collection($lots->items())
        ]);
    }

    /**
     * @OA\Get(
     *     path="/exchange-lots/{id}",
     *     summary="Get exchange lot by ID",
     *     tags={"Exchange Lots"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Successful response"),
     *     @OA\Response(response=401, description="Unauthorized"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function show(ExchangeLot $lot): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $lot->load(['cryptoCurrency', 'node', 'bank', 'fromFiatCurrency', 'fromCryptoCurrency', 'toFiatCurrency', 'toCryptoCurrency'])
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
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"from_currency","to_currency","sell_amount","buy_amounts","crypto_currency_id","wallet_address"},
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
     *     @OA\Response(response=201, description="Created"),
     *     @OA\Response(response=401, description="Unauthorized"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'node_id' => 'required|exists:nodes,id',
            'from_currency' => 'required|string|max:20',
            'to_currency' => 'required|string|max:20',
            'sell_amount' => 'required|numeric|min:0',
            'buy_amounts' => 'required|array',
            'buy_amounts.*' => 'numeric|min:0',
            'rate' => 'nullable|numeric|min:0',
            'crypto_currency_id' => 'required|exists:crypto_currencies,id',
            'wallet_address' => 'required|string',
            'bank_id' => 'nullable|exists:banks,id',
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
            'aml' => 'nullable|integer|min:0|max:100',
            'expires_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();
        $buyAmounts = array_values(array_filter(array_map('floatval', $validated['buy_amounts']), fn($v) => $v > 0));
        if (empty($buyAmounts)) {
            return response()->json([
                'success' => false,
                'errors' => ['buy_amounts' => ['At least one positive amount is required.']]
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
                    'errors' => ['rate' => ['Rate is required when multiple buy_amounts are specified.']]
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
        if (!$fromFiat && !$fromCrypto) {
            return response()->json(['success' => false, 'errors' => ['from_currency' => ['Unknown currency code.']]], 422);
        }
        if (!$toFiat && !$toCrypto) {
            return response()->json(['success' => false, 'errors' => ['to_currency' => ['Unknown currency code.']]], 422);
        }
        $validated['from_fiat_currency_id'] = $fromFiat?->id;
        $validated['from_crypto_currency_id'] = $fromCrypto?->id;
        $validated['to_fiat_currency_id'] = $toFiat?->id;
        $validated['to_crypto_currency_id'] = $toCrypto?->id;
        $decoded = json_decode($validated['wallet_address'], true);
        if (!is_array($decoded) || empty($decoded['token_hash'])) {
            return response()->json([
                'success' => false,
                'errors' => ['wallet_address' => ['token_hash is required in public part of escrow token.']]
            ], 422);
        }
        $validated['wallet_hash'] = strtolower(trim($decoded['token_hash']));

        // Validate escrow token via Core before publishing lot (protection against fake tokens)
        $validation = $this->escrowTokenValidation->validateToken($validated['wallet_address']);
        if (!$validation['valid']) {
            return response()->json([
                'success' => false,
                'errors' => ['wallet_address' => [$validation['error'] ?? 'Escrow token validation failed. Token may be fake or invalid.']]
            ], 422);
        }

        // Store group hashes (SHA256) instead of plain text; null if no group
        $validated['test_group'] = !empty($validated['test_group'])
            ? hash('sha256', trim($validated['test_group']))
            : null;
        $validated['main_group'] = !empty($validated['main_group'])
            ? hash('sha256', trim($validated['main_group']))
            : null;

        unset($validated['from_currency'], $validated['to_currency']);
        $bankIds = $validated['bank_ids'] ?? null;
        unset($validated['bank_ids']);

        // AML: read from raw JSON so "aml": null is never lost (has()/input() can drop null in some setups)
        $input = \is_array($request->all()) ? $request->all() : [];
        $raw = $request->getContent();
        Log::info('[exchange-lots] store AML debug', [
            'raw_length' => $raw === null ? 0 : strlen($raw),
            'raw_preview' => $raw ? substr($raw, 0, 200) . (strlen($raw) > 200 ? '...' : '') : '',
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
                $validated['aml'] = ($v === null || $v === '') ? null : (int) $v;
                Log::info('[exchange-lots] store AML set from raw', ['validated_aml' => $validated['aml']]);
            }
        } elseif (array_key_exists('aml', $input)) {
            $v = $input['aml'];
            $validated['aml'] = ($v === null || $v === '') ? null : (int) $v;
            Log::info('[exchange-lots] store AML set from input', ['validated_aml' => $validated['aml']]);
        }

        $lot = ExchangeLot::create($validated);
        Log::info('[exchange-lots] store lot created', ['lot_id' => $lot->id, 'lot_aml' => $lot->aml]);
        if (!empty($bankIds)) {
            $lot->banks()->sync(array_values(array_unique(array_map('intval', $bankIds))));
        }

        // Create or get mailbox for lot chat (IMAP) — see docs/LOT_MAILBOX_AND_IMAP_CHAT.md
        $mailbox = null;
        Log::info('[exchange-lots] Creating lot: before mailbox step', [
            'lot_id' => $lot->id,
            'wallet_hash_preview' => substr($validated['wallet_hash'] ?? '', 0, 16) . '...',
        ]);
        try {
            $currency = $lot->cryptoCurrency?->code ?? $decoded['currency'] ?? 'crypto';
            Log::info('[exchange-lots] Calling ensureMailbox', ['lot_id' => $lot->id, 'currency' => $currency]);
            $mailbox = $this->lotMailboxService->ensureMailbox(
                $validated['wallet_address'],
                $validated['wallet_hash'],
                $currency
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
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="from_currency", type="string"),
     *             @OA\Property(property="to_currency", type="string"),
     *             @OA\Property(property="sell_amount", type="number"),
     *             @OA\Property(property="buy_amounts", type="array", @OA\Items(type="number")),
     *             @OA\Property(property="bank_id", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Updated"),
     *     @OA\Response(response=401, description="Unauthorized"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(Request $request, ExchangeLot $lot): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => 'required|string',
            'from_currency' => 'required|string|max:20',
            'to_currency' => 'required|string|max:20',
            'sell_amount' => 'required|numeric|min:0',
            'buy_amounts' => 'required|array',
            'buy_amounts.*' => 'numeric|min:0',
            'rate' => 'nullable|numeric|min:0',
            'bank_id' => 'nullable|exists:banks,id',
            'bank_ids' => 'nullable|array',
            'bank_ids.*' => 'integer|exists:banks,id',
            'test_group' => 'nullable|string|max:128',
            'main_group' => 'nullable|string|max:128',
            'note' => 'nullable|string|max:65535',
            'seller_locale' => 'nullable|string|max:10',
            'aml' => 'nullable|integer|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();
        $buyAmounts = array_values(array_filter(array_map('floatval', $validated['buy_amounts']), fn($v) => $v > 0));
        if (empty($buyAmounts)) {
            return response()->json([
                'success' => false,
                'errors' => ['buy_amounts' => ['At least one positive amount is required.']]
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
                    'errors' => ['rate' => ['Rate is required when multiple buy_amounts are specified.']]
                ], 422);
            }
        }

        // Compare incoming escrow token with lot's stored token (no Core call on update)
        $decoded = json_decode($validated['wallet_address'], true);
        if (!is_array($decoded) || empty($decoded['token_hash'])) {
            return response()->json([
                'success' => false,
                'errors' => ['wallet_address' => ['token_hash is required in public part of escrow token.']]
            ], 422);
        }
        $incomingHash = strtolower(trim($decoded['token_hash']));
        $storedHash = strtolower(trim($lot->wallet_hash ?? ''));
        if ($incomingHash !== $storedHash) {
            return response()->json([
                'success' => false,
                'errors' => ['wallet_address' => ['Escrow token does not match the lot. Cannot change token when updating.']]
            ], 422);
        }

        $fromCode = strtoupper($validated['from_currency']);
        $toCode = strtoupper($validated['to_currency']);
        $fromFiat = FiatCurrency::where('code', $fromCode)->first();
        $fromCrypto = $fromFiat ? null : CryptoCurrency::whereRaw('LOWER(code) = ?', [strtolower($fromCode)])->first();
        $toFiat = FiatCurrency::where('code', $toCode)->first();
        $toCrypto = $toFiat ? null : CryptoCurrency::whereRaw('LOWER(code) = ?', [strtolower($toCode)])->first();
        if (!$fromFiat && !$fromCrypto) {
            return response()->json(['success' => false, 'errors' => ['from_currency' => ['Unknown currency code.']]], 422);
        }
        if (!$toFiat && !$toCrypto) {
            return response()->json(['success' => false, 'errors' => ['to_currency' => ['Unknown currency code.']]], 422);
        }

        $updateData = [
            'from_fiat_currency_id' => $fromFiat?->id,
            'from_crypto_currency_id' => $fromCrypto?->id,
            'to_fiat_currency_id' => $toFiat?->id,
            'to_crypto_currency_id' => $toCrypto?->id,
            'sell_amount' => $validated['sell_amount'],
            'buy_amount_min' => min($buyAmounts),
            'buy_amount_max' => max($buyAmounts),
            'buy_amounts' => $buyAmounts,
            'rate' => $rateValue,
            'bank_id' => $validated['bank_id'] ?? null,
        ];
        $updateData['test_group'] = !empty($validated['test_group'] ?? null)
            ? hash('sha256', trim($validated['test_group']))
            : null;
        $updateData['main_group'] = !empty($validated['main_group'] ?? null)
            ? hash('sha256', trim($validated['main_group']))
            : null;
        $updateData['note'] = $validated['note'] ?? null;
        if (array_key_exists('seller_locale', $validated) && $validated['seller_locale'] !== null) {
            $updateData['seller_locale'] = $validated['seller_locale'];
        }
        // AML: read from raw JSON so "aml": null is never lost
        $raw = $request->getContent();
        $input = \is_array($request->all()) ? $request->all() : [];
        Log::info('[exchange-lots] update AML debug', [
            'lot_id' => $lot->id,
            'raw_length' => $raw === null ? 0 : strlen($raw),
            'raw_preview' => $raw ? substr($raw, 0, 200) . (strlen($raw) > 200 ? '...' : '') : '',
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
                $updateData['aml'] = ($v === null || $v === '') ? null : (int) $v;
                Log::info('[exchange-lots] update AML set from raw', ['updateData_aml' => $updateData['aml']]);
            }
        } else {
            if (array_key_exists('aml', $input)) {
                $v = $input['aml'];
                $updateData['aml'] = ($v === null || $v === '') ? null : (int) $v;
                Log::info('[exchange-lots] update AML set from input', ['updateData_aml' => $updateData['aml']]);
            }
        }
        $lot->update($updateData);
        Log::info('[exchange-lots] update done', ['lot_id' => $lot->id, 'lot_aml_after' => $lot->fresh()->aml]);

        if (array_key_exists('bank_ids', $validated)) {
            $bankIds = $validated['bank_ids'] ?? [];
            $lot->banks()->sync(empty($bankIds) ? [] : array_values(array_unique(array_map('intval', $bankIds))));
        }

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\ExchangeLotResource::make($lot->load(['cryptoCurrency', 'node', 'bank', 'fromFiatCurrency', 'fromCryptoCurrency', 'toFiatCurrency', 'toCryptoCurrency']))
        ]);
    }

    /**
     * @OA\Post(
     *     path="/exchange-lots/{id}/delete",
     *     summary="Delete exchange lot",
     *     tags={"Exchange Lots"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
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
            'message' => 'Lot successfully deleted'
        ]);
    }

    /**
     * @OA\Post(
     *     path="/exchange-lots/{id}/set-active",
     *     summary="Set lot active/inactive",
     *     tags={"Exchange Lots"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(required={"is_active"}, @OA\Property(property="is_active", type="boolean"))
     *     ),
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
                'errors' => $validator->errors()
            ], 422);
        }

        $lot->update(['is_active' => $request->boolean('is_active')]);

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\ExchangeLotResource::make($lot->load(['cryptoCurrency', 'node', 'bank', 'fromFiatCurrency', 'fromCryptoCurrency', 'toFiatCurrency', 'toCryptoCurrency']))
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
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(required={"message"}, @OA\Property(property="message", type="string"))
     *     ),
     *     @OA\Response(response=200, description="Message sent"),
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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $lot->load(['cryptoCurrency', 'node', 'bank', 'fromFiatCurrency', 'fromCryptoCurrency', 'toFiatCurrency', 'toCryptoCurrency', 'banks']);
        $currency = $lot->cryptoCurrency?->code ?? 'crypto';
        $sellerEmail = $this->lotMailboxService->getMailboxEmailForAddress($lot->wallet_address ?? '', $currency);
        // Fallback for exported/imported lots: use DB field when mailbox lookup fails (e.g. lot_mailboxes not imported)
        if (!$sellerEmail && !empty($lot->seller_lot_email)) {
            $sellerEmail = (string) $lot->seller_lot_email;
            Log::info('[exchange-lots] sendMessageToSeller: using seller_lot_email from DB (mailbox lookup had no match)', [
                'lot_id' => $lot->id,
                'seller_lot_email' => $sellerEmail,
            ]);
        }

        Log::info('[exchange-lots] sendMessageToSeller: attempt', [
            'lot_id' => $lot->id,
            'wallet_address' => $lot->wallet_address,
            'currency' => $currency,
            'seller_email' => $sellerEmail ?: '(none)',
        ]);

        if (!$sellerEmail) {
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
        $requesterMailboxEmail = null;
        if (is_array($tokenData)) {
            $requesterWallet = $tokenData['wallet_address'] ?? '';
            $requesterCurrency = $tokenData['currency'] ?? '';
            if ($requesterWallet !== '' && $requesterCurrency !== '') {
                $requesterMailboxEmail = $this->lotMailboxService->getMailboxEmailForAddress($requesterWallet, $requesterCurrency);
                if ($requesterMailboxEmail !== null && strcasecmp($requesterMailboxEmail, $sellerEmail) === 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot send message to yourself. This is your lot.',
                        'error_code' => 'cannot_send_to_yourself',
                    ], 400);
                }
            }
        }

        $messageType = $request->input('message_type');

        // start_deal: atomically set buyer_lot_email only if currently NULL/empty (avoid race)
        // Also ensure buyer's mailbox exists; return mailbox config so client can read messages via IMAP
        $buyerMailboxConfig = null;
        if ($messageType === 'start_deal' && $requesterMailboxEmail !== null && $requesterMailboxEmail !== '') {
            $updateData = ['buyer_lot_email' => $requesterMailboxEmail];
            $selectedAmount = $request->input('selected_amount');
            if (is_numeric($selectedAmount) && (float) $selectedAmount > 0) {
                $updateData['buyer_selected_amount'] = (float) $selectedAmount;
            }
            $tokenHash = '';
            $publicPart = $request->header('X-Escrow-Token') ?? $request->input('escrow_token');
            if ($publicPart && is_string($publicPart)) {
                $decoded = json_decode($publicPart, true);
                $tokenHash = isset($decoded['token_hash']) ? trim((string) $decoded['token_hash']) : '';
                if ($tokenHash !== '' && strlen($tokenHash) <= 64) {
                    $updateData['buyer_escrow_token_hash'] = $tokenHash;
                }
            }
            $updated = DB::table('exchange_lots')
                ->where('id', $lot->id)
                ->where(function ($q) {
                    $q->whereNull('buyer_lot_email')->orWhere('buyer_lot_email', '');
                })
                ->update($updateData);
            if ($updated === 0) {
                Log::warning('[exchange-lots] sendMessageToSeller: lot already has buyer', ['lot_id' => $lot->id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Another buyer has already started a deal on this lot.',
                    'error_code' => 'lot_taken',
                ], 409);
            }
            if ($publicPart && is_string($publicPart) && $tokenHash !== '') {
                try {
                    $buyerMailboxConfig = $this->lotMailboxService->ensureMailbox($publicPart, $tokenHash, $requesterCurrency);
                } catch (\Throwable $e) {
                    Log::warning('[exchange-lots] sendMessageToSeller: ensureMailbox for buyer failed (non-blocking)', [
                        'lot_id' => $lot->id,
                        'buyer_email' => $requesterMailboxEmail,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            Log::info('[exchange-lots] sendMessageToSeller: buyer_lot_email set', ['lot_id' => $lot->id, 'buyer_lot_email' => $requesterMailboxEmail]);
        }

        // buyer_exited: clear buyer_lot_email only for this buyer
        if ($messageType === 'buyer_exited' && $requesterMailboxEmail !== null && $requesterMailboxEmail !== '') {
            DB::table('exchange_lots')
                ->where('id', $lot->id)
                ->where('buyer_lot_email', $requesterMailboxEmail)
                ->update(['buyer_lot_email' => null]);
            Log::info('[exchange-lots] sendMessageToSeller: buyer_lot_email cleared', ['lot_id' => $lot->id]);
        }

        // Send from noreply@ with app name (XBoard) — template email, not shown in app chat
        $fromAddress = config('mail.from.address', 'noreply@xdexboard.com');
        $fromName = config('mail.from.name', 'XBoard');

        $messageText = $request->input('message', '');
        $buyerLocale = $request->input('buyer_locale', 'en');
        $subject = $this->getDealMessageSubject($lot, $buyerLocale);
        $body = $messageText;

        Log::info('[exchange-lots] sendMessageToSeller: sending mail', [
            'lot_id' => $lot->id,
            'to' => $sellerEmail,
            'from' => $fromAddress,
            'subject' => $subject,
            'message_length' => strlen($messageText),
        ]);

        try {
            Mail::raw($body, function ($message) use ($sellerEmail, $fromAddress, $fromName, $subject) {
                $message->to($sellerEmail);
                $message->subject($subject);
                $message->from($fromAddress, $fromName);
            });
            if ($requesterMailboxEmail !== null && $requesterMailboxEmail !== '') {
                $this->lotMailboxService->writeBuyerSentCopyToMailbox($requesterMailboxEmail, (string) $lot->id, $messageText);
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
                'message' => 'Failed to send message: ' . $e->getMessage()
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
     * Send a message from the seller (lot owner) to the buyer.
     * Only the seller can call this; email is delivered to the buyer's lot mailbox (buyer_lot_email).
     *
     * @OA\Post(
     *     path="/exchange-lots/{lot}/send-message-to-buyer",
     *     summary="Send message to buyer (seller only)",
     *     tags={"Exchange Lots"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"message"}, @OA\Property(property="message", type="string"))),
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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $messageType = $request->input('message_type');
        $lot->load(['cryptoCurrency']);
        $currency = $lot->cryptoCurrency?->code ?? 'crypto';

        Log::info('[exchange-lots] sendMessageToBuyer: start', [
            'lot_id' => $lot->id,
            'lot_currency' => $currency,
            'lot_wallet_address_len' => strlen($lot->wallet_address ?? ''),
            'lot_wallet_address_preview' => $lot->wallet_address ? substr($lot->wallet_address, 0, 120) . (strlen($lot->wallet_address) > 120 ? '...' : '') : null,
        ]);

        $sellerEmail = $this->lotMailboxService->getMailboxEmailForAddress($lot->wallet_address ?? '', $currency);
        if (!$sellerEmail && !empty($lot->seller_lot_email)) {
            $sellerEmail = (string) $lot->seller_lot_email;
        }

        if (!$sellerEmail) {
            Log::warning('[exchange-lots] sendMessageToBuyer: no_seller_mailbox', ['lot_id' => $lot->id]);
            return response()->json([
                'success' => false,
                'message' => 'No seller mailbox for this lot.',
                'error_code' => 'no_seller_mailbox',
            ], 400);
        }

        $buyerEmail = $lot->buyer_lot_email;
        if (empty($buyerEmail)) {
            return response()->json([
                'success' => false,
                'message' => 'No buyer in deal for this lot.',
                'error_code' => 'no_buyer_in_deal',
            ], 400);
        }

        // Block seller_exited only when this buyer has been sent payment details: transactions are linked to buyer
        // via buyer_lot_email so that (in future) multiple buyers per lot can be supported; we only consider
        // transactions for the current buyer (lot.buyer_lot_email).
        if ($messageType === 'seller_exited' && Transaction::where('exchange_lot_id', $lot->id)->where('buyer_lot_email', $lot->buyer_lot_email)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Payment details have already been sent for this deal. Only the buyer can cancel the deal now.',
                'error_code' => 'only_buyer_can_cancel',
            ], 403);
        }

        $messageText = $request->input('message', '');
        $subject = $this->getDealMessageSubject($lot, 'en');
        $body = $messageText;

        // seller_exited: send notification to buyer then clear buyer_lot_email so the lot can accept new deals
        if ($messageType === 'seller_exited') {
            DB::table('exchange_lots')
                ->where('id', $lot->id)
                ->update(['buyer_lot_email' => null]);
            Log::info('[exchange-lots] sendMessageToBuyer: buyer_lot_email cleared (seller_exited)', ['lot_id' => $lot->id]);
        }

        Log::info('[exchange-lots] sendMessageToBuyer: sending mail', [
            'lot_id' => $lot->id,
            'to' => $buyerEmail,
            'subject' => $subject,
        ]);

        try {
            Mail::raw($body, function ($message) use ($buyerEmail, $subject) {
                $message->to($buyerEmail);
                $message->subject($subject);
                $message->from(config('mail.from.address', 'noreply@xdexboard.com'), config('mail.from.name', 'XBoard'));
            });
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
                'message' => 'Failed to send message: ' . $e->getMessage()
            ], 500);
        }

        Log::info('[exchange-lots] sendMessageToBuyer: mail sent', [
            'lot_id' => $lot->id,
            'to' => $buyerEmail,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Message sent to buyer.',
        ]);
    }

    /**
     * Buyer cancels the deal: deletes transaction(s) and reservation(s) in Core, clears buyer_lot_email, notifies seller.
     * Only the buyer (escrow token hash matches lot.buyer_escrow_token_hash) can call this.
     */
    public function buyerCancelDeal(Request $request, ExchangeLot $lot): JsonResponse
    {
        $escrowToken = $request->input('escrow_token') ?? $request->header('X-Escrow-Token');
        if (empty($escrowToken) || !is_string($escrowToken)) {
            return response()->json([
                'success' => false,
                'message' => 'escrow_token is required (buyer\'s full token).',
                'error_code' => 'validation_error',
            ], 422);
        }

        if (empty($lot->buyer_escrow_token_hash)) {
            return response()->json([
                'success' => false,
                'message' => 'This lot has no buyer linked for cancellation.',
                'error_code' => 'no_buyer',
            ], 400);
        }

        $requestorHash = hash('sha256', $escrowToken);
        if (!hash_equals($lot->buyer_escrow_token_hash, $requestorHash)) {
            return response()->json([
                'success' => false,
                'message' => 'Only the buyer can cancel this deal.',
                'error_code' => 'forbidden',
            ], 403);
        }

        $transactions = Transaction::where('exchange_lot_id', $lot->id)->where('buyer_lot_email', $lot->buyer_lot_email)->get();
        $coreUrl = $this->getCoreApiUrl();
        $coreKey = $this->getCoreApiKey();
        $timeout = (int) config('services.core.timeout', 10);
        $cryptoType = $lot->cryptoCurrency?->code ?? 'xmr';

        foreach ($transactions as $tx) {
            try {
                $req = Http::timeout($timeout);
                if ($coreKey !== '') {
                    $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
                }
                $resp = $req->post($coreUrl . '/api/cancel-reservation-by-transaction', [
                    'transaction_id' => $tx->id,
                    'escrow_token' => $escrowToken,
                    'crypto_type' => $cryptoType,
                ]);
                if (!$resp->successful()) {
                    Log::warning('[exchange-lots] buyerCancelDeal: Core cancel-reservation failed', [
                        'lot_id' => $lot->id,
                        'transaction_id' => $tx->id,
                        'status' => $resp->status(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('[exchange-lots] buyerCancelDeal: Core request failed', [
                    'lot_id' => $lot->id,
                    'transaction_id' => $tx->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Transaction::where('exchange_lot_id', $lot->id)->where('buyer_lot_email', $lot->buyer_lot_email)->delete();
        DB::table('exchange_lots')->where('id', $lot->id)->update(['buyer_lot_email' => null]);

        $lot->load(['cryptoCurrency']);
        $sellerEmail = $this->lotMailboxService->getMailboxEmailForAddress($lot->wallet_address ?? '', $lot->cryptoCurrency?->code ?? 'crypto');
        if (!$sellerEmail && !empty($lot->seller_lot_email)) {
            $sellerEmail = (string) $lot->seller_lot_email;
        }
        if ($sellerEmail) {
            try {
                Mail::raw(
                    'The buyer has left the deal for Lot #' . $lot->id . '.',
                    function ($message) use ($sellerEmail, $lot) {
                        $message->to($sellerEmail);
                        $message->subject('Buyer left the deal — Lot #' . $lot->id);
                        $message->from(config('mail.from.address', 'noreply@xdexboard.com'), config('mail.from.name', 'XBoard'));
                    }
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
     * Create a deal transaction and send payment details email to the buyer (seller only).
     * Subject is human-readable; structured tag is in X-XBoard-Deal-Tag header (JSON).
     * See docs/DEAL_EMAIL_SUBJECT_TAG.md.
     *
     * @OA\Post(
     *     path="/exchange-lots/{lot}/send-payment-details",
     *     summary="Send payment details to buyer (seller only)",
     *     tags={"Exchange Lots"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"payment_type"},
     *         @OA\Property(property="payment_type", type="string", enum={"crypto","bank"}),
     *         @OA\Property(property="address", type="string", description="Crypto address (when payment_type=crypto)"),
     *         @OA\Property(property="crypto_type", type="string", description="e.g. xmr, xrp (when payment_type=crypto)"),
     *         @OA\Property(property="bank_swift", type="string", description="Bank SWIFT (when payment_type=bank, preferred)"),
     *         @OA\Property(property="bank_id", type="integer", description="Bank id (when payment_type=bank)"),
     *         @OA\Property(property="bank_data", type="string", description="Free-form transfer details (card, phone, etc.)")
     *     )),
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
        if ($paymentType === 'bank' && $lot->bank_id === null) {
            return response()->json([
                'success' => false,
                'message' => 'Lot has no bank selected for this deal.',
                'error_code' => 'validation_error',
            ], 422);
        }

        $lot->load(['cryptoCurrency', 'bank']);
        $currency = $lot->cryptoCurrency?->code ?? 'crypto';
        $buyerEmail = $lot->buyer_lot_email;
        if (empty($buyerEmail)) {
            return response()->json([
                'success' => false,
                'message' => 'No buyer in deal for this lot.',
                'error_code' => 'no_buyer_in_deal',
            ], 400);
        }

        $amount = $lot->buyer_selected_amount > 0
            ? (float) $lot->buyer_selected_amount
            : (float) $lot->sell_amount;

        $txHash = 'pd-' . $lot->id . '-' . now()->format('YmdHis') . '-' . bin2hex(random_bytes(4));
        $txHash = substr($txHash, 0, 64);

        $bankId = $paymentType === 'bank' ? $lot->bank_id : null;
        $bankDetails = $paymentType === 'bank' ? trim((string) $request->input('bank_data', '')) : null;
        $cryptoAddress = $paymentType === 'crypto' ? trim((string) $request->input('address', '')) : null;

        $transaction = Transaction::create([
            'tx_hash' => $txHash,
            'tx_type' => 'sale',
            'from_address' => 'buyer',
            'to_address' => 'seller',
            'amount' => $amount,
            'exchange_lot_id' => $lot->id,
            'buyer_lot_email' => $buyerEmail,
            'bank_id' => $bankId,
            'bank_details' => $bankDetails,
            'crypto_address' => $cryptoAddress,
            'status' => 'pending',
            'is_test' => (bool) $lot->is_test,
            'test_group' => $lot->test_group,
        ]);

        $sellerEscrowToken = $request->input('escrow_token') ?? $request->header('X-Escrow-Token');
        if (!empty($sellerEscrowToken) && is_string($sellerEscrowToken)) {
            try {
                $coreUrl = $this->getCoreApiUrl();
                $coreKey = $this->getCoreApiKey();
                $timeout = (int) config('services.core.timeout', 10);
                $req = Http::timeout($timeout);
                if ($coreKey !== '') {
                    $req = $req->withHeaders(['X-Core-Key' => $coreKey]);
                }
                $req->post($coreUrl . '/api/reservations/set-transaction-id', [
                    'exchange_lot_id' => $lot->id,
                    'transaction_id' => $transaction->id,
                    'escrow_token' => $sellerEscrowToken,
                    'crypto_type' => $currency,
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
            $tag['bank_id'] = (int) $lot->bank_id;
            if ($bankDetails !== '') {
                $tag['bank_data'] = $bankDetails;
            }
        }
        $tagJson = json_encode($tag, JSON_UNESCAPED_UNICODE);

        $subject = 'Payment details — Lot #' . $lot->id;
        $bodyLines = [
            'Payment details for Lot #' . $lot->id . '.',
            '',
            $paymentType === 'crypto'
                ? ('Send ' . $amount . ' ' . ($tag['crypto'] ?? '') . ' to address: ' . ($tag['address'] ?? ''))
                : ('Bank transfer details (Lot #' . $lot->id . ').'),
            '',
            $request->input('message', '') ?: 'See details above.',
        ];
        $body = implode("\n", $bodyLines);

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
            });
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] sendPaymentDetails: failed to send mail', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to send email: ' . $e->getMessage(),
                'error_code' => 'mail_failed',
            ], 500);
        }

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
        if (!is_array($tokenData)) {
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
        if (!$buyerEmail) {
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
            });
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] testSendToBuyer: failed to send mail', [
                'to' => $buyerEmail,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to send: ' . $e->getMessage(),
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
            });
        } catch (\Throwable $e) {
            Log::warning('[exchange-lots] testSendToBuyerByEmail: failed', [
                'to' => $buyerEmail,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to send: ' . $e->getMessage(),
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
     * Subject line for "deal message" email, localized to buyer's locale (so seller sees subject in buyer's language).
     * MUST include lot number (id) in subject so the client can parse it when opening from notification (e.g. IDLE).
     */
    private function getDealMessageSubject(ExchangeLot $lot, string $buyerLocale): string
    {
        $locale = $buyerLocale ?: 'en';
        $templates = [
            'ru' => 'Сообщение по сделке — Лот #%s',
            'fr' => 'Message de deal — Lot #%s',
            'he' => 'הודעת עסקה — לוט #%s',
            'de' => 'Deal-Nachricht — Lot #%s',
            'es' => 'Mensaje de deal — Lote #%s',
            'ar' => 'رسالة صفقة — لوت #%s',
            'en' => 'Deal message — Lot #%s',
        ];
        $template = $templates[$locale] ?? $templates['en'];
        return sprintf($template, (string) $lot->id);
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

    /**
     * Email body: deal summary (same info as on Deal screen) in buyer's language, then buyer's message.
     * Labels localized by buyer_locale; no seller language in body.
     */
    private function getDealMessageBody(ExchangeLot $lot, string $buyerLocale, string $buyerMessage, ?float $selectedAmount): string
    {
        $locale = $buyerLocale ?: 'en';
        $strings = $this->getDealEmailStrings($locale);

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
        $amlNotConsidered = $strings['aml_not_considered'];
        $amlText = $aml !== null ? "{$amlLabel}: {$aml}%" : "{$amlLabel}: {$amlNotConsidered}";

        $lines = [
            $strings['summary_title'],
            '',
            $strings['pair'] . ': ' . $fromSym . ' → ' . $toSym,
            $strings['pay_range'] . ': ' . sprintf('%.2f', $minAmount) . '–' . sprintf('%.2f', $maxAmount) . ' ' . $toSym,
        ];
        if (count($buyAmounts) > 1 && $rate > 0) {
            $lines[] = $strings['rate'] . ': 1 ' . $fromSym . ' = ' . $rate . ' ' . $toSym;
            if ($selectedAmount !== null && $selectedAmount > 0) {
                $receiveAmount = $selectedAmount / $rate;
                $totalStr = $maxAmount >= 1 && $maxAmount == (int) $maxAmount ? sprintf('%.0f', $maxAmount) : sprintf('%.2f', $maxAmount);
                $payStr = $selectedAmount >= 1 && $selectedAmount == (int) $selectedAmount ? sprintf('%.0f', $selectedAmount) : sprintf('%.2f', $selectedAmount);
                $receiveStr = $receiveAmount >= 1 && $receiveAmount == (int) $receiveAmount ? sprintf('%.0f', $receiveAmount) : rtrim(rtrim(sprintf('%.6f', $receiveAmount), '0'), '.');
                $lines[] = sprintf($strings['for_amount_you_get'], $payStr, $toSym, $totalStr, $toSym, $receiveStr, $fromSym);
            }
        }
        $lines[] = $amlText;
        $lines[] = $strings['lot_id'] . ': ' . $lot->id;
        if ($lot->relationLoaded('banks') && $lot->banks->isNotEmpty()) {
            $bankNames = $lot->banks->map(function (Bank $b) {
                $swift = $b->swift_code ? " ({$b->swift_code})" : '';
                return $b->name . $swift;
            })->values()->all();
            $lines[] = $strings['accept_from_banks'] . ': ' . implode(', ', $bankNames);
        }
        $lines[] = '';
        $lines[] = $strings['buyer_message'];
        $lines[] = $buyerMessage;

        return implode("\n", $lines);
    }

    /**
     * Localized strings for deal summary email (buyer's language).
     *
     * @return array<string, string>
     */
    private function getDealEmailStrings(string $locale): array
    {
        $en = [
            'summary_title' => 'Deal summary (buyer\'s view)',
            'pair' => 'Pair',
            'pay_range' => 'Pay range',
            'rate' => 'Rate',
            'for_amount_you_get' => 'For %s %s (of %s %s): you get %s %s',
            'aml' => 'AML',
            'aml_not_considered' => '—',
            'lot_id' => 'Lot ID',
            'accept_from_banks' => 'Accept from banks',
            'buyer_message' => 'Buyer\'s message:',
        ];
        $ru = [
            'summary_title' => 'Сводка по сделке (вид покупателя)',
            'pair' => 'Пара',
            'pay_range' => 'Диапазон оплаты',
            'rate' => 'Курс',
            'for_amount_you_get' => 'За %s %s (из %s %s): получите %s %s',
            'aml' => 'AML',
            'aml_not_considered' => '—',
            'lot_id' => 'ID лота',
            'accept_from_banks' => 'Принимает платежи с банков',
            'buyer_message' => 'Сообщение покупателя:',
        ];
        $fr = [
            'summary_title' => 'Résumé du deal (vue acheteur)',
            'pair' => 'Paire',
            'pay_range' => 'Fourchette de paiement',
            'rate' => 'Taux',
            'for_amount_you_get' => 'Pour %s %s (sur %s %s) : vous recevez %s %s',
            'aml' => 'AML',
            'aml_not_considered' => '—',
            'lot_id' => 'ID du lot',
            'accept_from_banks' => 'Accepte les virements depuis les banques',
            'buyer_message' => 'Message de l\'acheteur :',
        ];
        $he = [
            'summary_title' => 'סיכום עסקה (תצוגת קונה)',
            'pair' => 'צמד',
            'pay_range' => 'טווח תשלום',
            'rate' => 'שער',
            'for_amount_you_get' => 'עבור %s %s (מתוך %s %s): תקבל %s %s',
            'aml' => 'AML',
            'aml_not_considered' => '—',
            'lot_id' => 'מזהה מנה',
            'accept_from_banks' => 'מקבל תשלומים מהבנקים',
            'buyer_message' => 'הודעת הקונה:',
        ];
        $all = ['en' => $en, 'ru' => $ru, 'fr' => $fr, 'he' => $he];
        return $all[$locale] ?? $en;
    }

    private function getCoreApiUrl(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (!preg_match('/^(sandbox\.|dev\.)/', $host)) {
            return rtrim((string) config('services.core_production.url'), '/');
        }
        return rtrim((string) config('services.core.url'), '/');
    }

    private function getCoreApiKey(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (!preg_match('/^(sandbox\.|dev\.)/', $host)) {
            return (string) config('services.core_production.key');
        }
        return (string) config('services.core.key');
    }
} 