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
            ->with(['cryptoCurrency', 'node', 'bank']);

        if ($request->has('is_test')) {
            if ($request->is_test === 'true') {
                $query->test($request->test_group);
            } else {
                $query->where('is_test', false);
            }
        }

        if ($request->filled('from_currency')) {
            $query->where('from_currency', strtoupper($request->from_currency));
        }
        if ($request->filled('to_currency')) {
            $query->where('to_currency', strtoupper($request->to_currency));
        }
        if ($request->has('node_id')) {
            $query->where('node_id', $request->node_id);
        }
        if ($request->has('for_individuals') && $request->for_individuals === 'true') {
            $query->forIndividuals();
        }
        if ($request->has('for_legal_entities') && $request->for_legal_entities === 'true') {
            $query->forLegalEntities();
        }
        if ($request->filled('bank_id')) {
            $query->where('bank_id', $request->bank_id);
        }
        if ($request->filled('wallet_address')) {
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
            'data' => $lot->load(['cryptoCurrency', 'node', 'bank'])
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
            'crypto_currency_id' => 'required|exists:crypto_currencies,id',
            'wallet_address' => 'required|string|max:500',
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
        $validated['from_currency'] = strtoupper($validated['from_currency']);
        $validated['to_currency'] = strtoupper($validated['to_currency']);

        $lot = ExchangeLot::create($validated);

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\ExchangeLotResource::make($lot->load(['cryptoCurrency', 'node', 'bank']))
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
            'data' => $lot->load(['cryptoCurrency', 'node', 'bank'])
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