<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\Country;
use App\Models\CryptoCurrency;
use App\Models\ExchangeLot;
use App\Models\FiatCurrency;
use App\Services\EscrowTokenValidationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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

        // Send from noreply@ with app name (XBoard) — template email, not shown in app chat
        $fromAddress = config('mail.from.address', 'noreply@xdexboard.com');
        $fromName = config('mail.from.name', 'XBoard');

        $messageText = $request->input('message', '');
        $buyerLocale = $request->input('buyer_locale', 'en');
        $selectedAmount = $request->has('selected_amount') ? (float) $request->input('selected_amount') : null;
        $subject = $this->getDealMessageSubject($lot, $buyerLocale);
        $body = $this->getDealMessageBody($lot, $buyerLocale, $messageText, $selectedAmount);

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
            Log::info('[exchange-lots] sendMessageToSeller: mail sent successfully', [
                'lot_id' => $lot->id,
                'to' => $sellerEmail,
            ]);
        } catch (\Exception $e) {
            Log::warning('[exchange-lots] sendMessageToSeller: failed to send mail', [
                'lot_id' => $lot->id,
                'to' => $sellerEmail,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to send message: ' . $e->getMessage()
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Message sent to seller.'
        ]);
    }

    /**
     * Get deal message delivery/read status (IMAP/Maildir). Used by buyer after "Start deal" to show indicator.
     *
     * @OA\Get(
     *     path="/exchange-lots/{lot}/deal-message-status",
     *     summary="Deal message status (delivery/read)",
     *     tags={"Exchange Lots"},
     *     @OA\Response(response=200, description="delivered and read flags")
     * )
     */
    public function dealMessageStatus(ExchangeLot $lot): \Illuminate\Http\JsonResponse
    {
        $lot->load(['cryptoCurrency']);
        $currency = $lot->cryptoCurrency?->code ?? 'crypto';
        $status = $this->lotMailboxService->getDealMessageStatus(
            $lot->wallet_address ?? '',
            $currency,
            $lot->id
        );
        if ($status === null) {
            return response()->json([
                'success' => true,
                'data' => ['delivered' => false, 'read' => false],
            ]);
        }
        return response()->json([
            'success' => true,
            'data' => $status,
        ]);
    }

    /**
     * Subject line for "deal message" email, localized to buyer's locale (so seller sees subject in buyer's language).
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
        return sprintf($template, $lot->id);
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
} 