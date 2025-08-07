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
 *     description="API эндпоинты для работы с транзакциями"
 * )
 */

class TransactionController extends Controller
{
    /**
     * Получить список транзакций
     */
    public function index(Request $request): JsonResponse
    {
        $query = Transaction::with(['exchangeLot']);

        // Фильтрация по тестовому режиму
        if ($request->has('is_test')) {
            if ($request->is_test === 'true') {
                $query->test($request->test_group);
            } else {
                $query->where('is_test', false);
            }
        }

        // Фильтрация по типу транзакции
        if ($request->has('tx_type')) {
            $query->byType($request->tx_type);
        }

        // Фильтрация по адресу
        if ($request->has('address')) {
            $query->byAddress($request->address);
        }

        // Фильтрация по статусу
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Фильтрация по токену
        if ($request->has('token')) {
            $query->where('token', $request->token);
        }

        // Фильтрация по лоту
        if ($request->has('exchange_lot_id')) {
            $query->where('exchange_lot_id', $request->exchange_lot_id);
        }

        // Сортировка
        $query->orderBy('created_at', 'desc');

        $transactions = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $transactions
        ]);
    }

    /**
     * Получить конкретную транзакцию
     */
    public function show(Transaction $transaction): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $transaction->load(['exchangeLot'])
        ]);
    }

    /**
     * Создать новую транзакцию
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
     * Обновить транзакцию
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
     * Получить статистику транзакций
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