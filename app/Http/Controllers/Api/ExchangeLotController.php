<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExchangeLot;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

/**
 * @OA\Tag(
 *     name="Exchange Lots",
 *     description="Endpoints for managing exchange lots"
 * )
 */

class ExchangeLotController extends Controller
{
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
        $query = ExchangeLot::active()
            ->with(['cryptoCurrency', 'country', 'bank', 'sellCurrency', 'sellCryptoCurrency', 'buyCurrency', 'buyCryptoCurrency']);

        // Filter by test mode
        if ($request->has('is_test')) {
            if ($request->is_test === 'true') {
                $query->test($request->test_group);
            } else {
                $query->where('is_test', false);
            }
        }

        // Filter by currencies
        // Support both old format (sell_currency_type/id, buy_currency_type/id) and new format (from_currency, to_currency)
        if ($request->has('from_currency')) {
            // New format: from_currency is symbol (e.g., "BTC", "USD")
            $fromCurrencySymbol = strtoupper($request->from_currency);
            $fromCurrency = \App\Models\FiatCurrency::where('code', $fromCurrencySymbol)->first();
            if (!$fromCurrency) {
                $fromCurrency = \App\Models\CryptoCurrency::byCode($request->from_currency)->first();
                if ($fromCurrency) {
                    $query->where('sell_currency_type', 'crypto')
                          ->where('sell_currency_id', $fromCurrency->id);
                }
            } else {
                $query->where('sell_currency_type', 'fiat')
                      ->where('sell_currency_id', $fromCurrency->id);
            }
        } elseif ($request->has('sell_currency_type')) {
            $query->where('sell_currency_type', $request->sell_currency_type);
        }
        
        if ($request->has('sell_currency_id')) {
            $query->where('sell_currency_id', $request->sell_currency_id);
        }
        
        if ($request->has('to_currency')) {
            // New format: to_currency is symbol (e.g., "BTC", "USD")
            $toCurrencySymbol = strtoupper($request->to_currency);
            $toCurrency = \App\Models\FiatCurrency::where('code', $toCurrencySymbol)->first();
            if (!$toCurrency) {
                $toCurrency = \App\Models\CryptoCurrency::byCode($request->to_currency)->first();
                if ($toCurrency) {
                    $query->where('buy_currency_type', 'crypto')
                          ->where('buy_currency_id', $toCurrency->id);
                }
            } else {
                $query->where('buy_currency_type', 'fiat')
                      ->where('buy_currency_id', $toCurrency->id);
            }
        } elseif ($request->has('buy_currency_type')) {
            $query->where('buy_currency_type', $request->buy_currency_type);
        }
        
        if ($request->has('buy_currency_id')) {
            $query->where('buy_currency_id', $request->buy_currency_id);
        }

        // Filter by client type
        if ($request->has('for_individuals')) {
            if ($request->for_individuals === 'true') {
                $query->forIndividuals();
            }
        }
        if ($request->has('for_legal_entities')) {
            if ($request->for_legal_entities === 'true') {
                $query->forLegalEntities();
            }
        }

        // Filter by country
        if ($request->has('country_id')) {
            $query->where('country_id', $request->country_id);
        }

        // Filter by bank
        if ($request->has('bank_id')) {
            $query->where('bank_id', $request->bank_id);
        }

        // Search by wallet address
        if ($request->has('wallet_address')) {
            $query->where('wallet_address', $request->wallet_address);
        }

        // Sorting
        $query->orderBy('created_at', 'desc');

        $lots = $query->paginate($request->get('per_page', 20));

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
            'data' => $lot->load(['cryptoCurrency', 'country', 'bank'])
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
     *             required={"crypto_currency_id","wallet_address","sell_currency_type","sell_currency_id","sell_amount","buy_currency_type","buy_currency_id","buy_amount"},
     *             @OA\Property(property="crypto_currency_id", type="integer"),
     *             @OA\Property(property="wallet_address", type="string"),
     *             @OA\Property(property="sell_currency_type", type="string", enum={"fiat","crypto"}),
     *             @OA\Property(property="sell_currency_id", type="integer"),
     *             @OA\Property(property="sell_amount", type="number", format="float"),
     *             @OA\Property(property="buy_currency_type", type="string", enum={"fiat","crypto"}),
     *             @OA\Property(property="buy_currency_id", type="integer"),
     *             @OA\Property(property="buy_amount", type="number", format="float"),
     *         )
     *     ),
     *     @OA\Response(response=201, description="Created"),
     *     @OA\Response(response=401, description="Unauthorized"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->all();

        // Optional: accept from_currency / to_currency (codes) instead of sell_* / buy_* IDs
        if (!empty($data['from_currency']) && !empty($data['to_currency'])) {
            $fromCode = strtoupper((string) $data['from_currency']);
            $toCode = strtoupper((string) $data['to_currency']);
            $fromFiat = \App\Models\FiatCurrency::where('code', $fromCode)->first();
            $fromCrypto = \App\Models\CryptoCurrency::byCode($data['from_currency'])->first();
            $toFiat = \App\Models\FiatCurrency::where('code', $toCode)->first();
            $toCrypto = \App\Models\CryptoCurrency::byCode($data['to_currency'])->first();
            if ($fromFiat) {
                $data['sell_currency_type'] = 'fiat';
                $data['sell_currency_id'] = $fromFiat->id;
            } elseif ($fromCrypto) {
                $data['sell_currency_type'] = 'crypto';
                $data['sell_currency_id'] = $fromCrypto->id;
            }
            if ($toFiat) {
                $data['buy_currency_type'] = 'fiat';
                $data['buy_currency_id'] = $toFiat->id;
            } elseif ($toCrypto) {
                $data['buy_currency_type'] = 'crypto';
                $data['buy_currency_id'] = $toCrypto->id;
            }
        }

        $validator = Validator::make($data, [
            'crypto_currency_id' => 'required|exists:crypto_currencies,id',
            'wallet_address' => 'required|string|max:255',
            'sell_currency_type' => 'required|in:fiat,crypto',
            'sell_currency_id' => 'required|integer',
            'sell_amount' => 'required|numeric|min:0',
            'buy_currency_type' => 'required|in:fiat,crypto',
            'buy_currency_id' => 'required|integer',
            'buy_amount' => 'required|numeric|min:0',
            'from_currency' => 'nullable|string|max:20',
            'to_currency' => 'nullable|string|max:20',
            'country_id' => 'nullable|exists:countries,id',
            'bank_id' => 'nullable|exists:banks,id',
            'bank_account' => 'nullable|string|max:100',
            'payment_purpose' => 'nullable|string|max:255',
            'contract_url' => 'nullable|url|max:255',
            'is_for_individuals' => 'boolean',
            'is_for_legal_entities' => 'boolean',
            'is_test' => 'boolean',
            'test_group' => 'nullable|string|max:50',
            'expires_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();
        unset($validated['from_currency'], $validated['to_currency']);
        $lot = ExchangeLot::create($validated);

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\ExchangeLotResource::make($lot->load(['cryptoCurrency', 'country', 'bank', 'sellCurrency', 'sellCryptoCurrency', 'buyCurrency', 'buyCryptoCurrency']))
        ], 201);
    }

    /**
     * @OA\Put(
     *     path="/exchange-lots/{id}",
     *     summary="Update exchange lot",
     *     tags={"Exchange Lots"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="is_active", type="boolean"),
     *             @OA\Property(property="expires_at", type="string", format="date-time"),
     *             @OA\Property(property="bank_account", type="string"),
     *             @OA\Property(property="payment_purpose", type="string"),
     *             @OA\Property(property="contract_url", type="string"),
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
            'is_active' => 'boolean',
            'expires_at' => 'nullable|date',
            'bank_account' => 'nullable|string|max:100',
            'payment_purpose' => 'nullable|string|max:255',
            'contract_url' => 'nullable|url|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $lot->update($validator->validated());

        return response()->json([
            'success' => true,
            'data' => $lot->load(['cryptoCurrency', 'country', 'bank'])
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/exchange-lots/{id}",
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
} 