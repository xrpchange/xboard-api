<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

/**
 * @OA\Tag(
 *     name="Transactions",
 *     description="Endpoints for managing transactions"
 * )
 */

class TransactionController extends Controller
{
    /**
     * @OA\Get(
     *     path="/transactions",
     *     summary="List transactions",
     *     tags={"Transactions"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Successful response"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $query = Transaction::with(['exchangeLot']);

        // Filter by test mode
        if ($request->has('is_test')) {
            if ($request->is_test === 'true') {
                $query->test($request->test_group);
            } else {
                $query->where('is_test', false);
            }
        }

        // Filter by transaction type
        if ($request->has('tx_type')) {
            $query->byType($request->tx_type);
        }

        // Filter by address
        if ($request->has('address')) {
            $query->byAddress($request->address);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by token
        if ($request->has('token')) {
            $query->where('token', $request->token);
        }

        // Filter by lot
        if ($request->has('exchange_lot_id')) {
            $query->where('exchange_lot_id', $request->exchange_lot_id);
        }

        // Sorting
        $query->orderBy('created_at', 'desc');

        $transactions = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $transactions
        ]);
    }

    /**
     * @OA\Get(
     *     path="/transactions/{id}",
     *     summary="Get transaction by ID",
     *     tags={"Transactions"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Successful response"),
     *     @OA\Response(response=401, description="Unauthorized"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function show(Transaction $transaction): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $transaction->load(['exchangeLot'])
        ]);
    }

    /**
     * @OA\Post(
     *     path="/transactions",
     *     summary="Create transaction",
     *     tags={"Transactions"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"tx_hash","tx_type","from_address","to_address","amount"},
     *             @OA\Property(property="tx_hash", type="string"),
     *             @OA\Property(property="tx_type", type="string", enum={"deposit","withdrawal","fee","refund","compensation","sale"}),
     *             @OA\Property(property="from_address", type="string"),
     *             @OA\Property(property="to_address", type="string"),
     *             @OA\Property(property="amount", type="number", format="float"),
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
            'tx_hash' => 'required|string|max:64|unique:transactions',
            'tx_type' => 'required|in:deposit,withdrawal,fee,refund,compensation,sale',
            'from_address' => 'required|string|max:50',
            'to_address' => 'required|string|max:50',
            'amount' => 'required|numeric|min:0',
            'token' => 'nullable|string|max:500',
            'exchange_lot_id' => 'nullable|exists:exchange_lots,id',
            'status' => 'in:pending,completed,failed',
            'is_test' => 'boolean',
            'test_group' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $transaction = Transaction::create($validator->validated());

        return response()->json([
            'success' => true,
            'data' => $transaction->load(['exchangeLot'])
        ], 201);
    }

    /**
     * @OA\Put(
     *     path="/transactions/{id}",
     *     summary="Update transaction",
     *     tags={"Transactions"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", enum={"pending","completed","failed"}),
     *         )
     *     ),
     *     @OA\Response(response=200, description="Updated"),
     *     @OA\Response(response=401, description="Unauthorized"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'in:pending,completed,failed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $transaction->update($validator->validated());

        return response()->json([
            'success' => true,
            'data' => $transaction->load(['exchangeLot'])
        ]);
    }

    /**
     * @OA\Get(
     *     path="/transactions/stats",
     *     summary="Get transactions statistics",
     *     tags={"Transactions"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Successful response"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function stats(Request $request): JsonResponse
    {
        $query = Transaction::query();

        // Фильтрация по тестовому режиму
        if ($request->has('is_test')) {
            if ($request->is_test === 'true') {
                $query->test($request->test_group);
            } else {
                $query->where('is_test', false);
            }
        }

        $stats = [
            'total_transactions' => $query->count(),
            'total_amount' => $query->sum('amount'),
            'by_type' => $query->selectRaw('tx_type, COUNT(*) as count, SUM(amount) as total_amount')
                ->groupBy('tx_type')
                ->get(),
            'by_status' => $query->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
} 