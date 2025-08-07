<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BuyTrigger;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class BuyTriggerController extends Controller
{
    /**
     * Получить список триггеров покупки
     */
    public function index(Request $request): JsonResponse
    {
        $query = BuyTrigger::active()
            ->with(['cryptoCurrency', 'country', 'bank']);

        // Фильтрация по тестовому режиму
        if ($request->has('is_test')) {
            if ($request->is_test === 'true') {
                $query->test($request->test_group);
            } else {
                $query->where('is_test', false);
            }
        }

        // Фильтрация по валютам
        if ($request->has('buy_currency_type')) {
            $query->where('buy_currency_type', $request->buy_currency_type);
        }
        if ($request->has('buy_currency_id')) {
            $query->where('buy_currency_id', $request->buy_currency_id);
        }
        if ($request->has('sell_currency_type')) {
            $query->where('sell_currency_type', $request->sell_currency_type);
        }
        if ($request->has('sell_currency_id')) {
            $query->where('sell_currency_id', $request->sell_currency_id);
        }

        // Фильтрация по типу клиентов
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

        // Фильтрация по стране
        if ($request->has('country_id')) {
            $query->where('country_id', $request->country_id);
        }

        // Фильтрация по банку
        if ($request->has('bank_id')) {
            $query->where('bank_id', $request->bank_id);
        }

        // Поиск по адресу кошелька
        if ($request->has('wallet_address')) {
            $query->where('wallet_address', $request->wallet_address);
        }

        // Сортировка
        $query->orderBy('created_at', 'desc');

        $triggers = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $triggers
        ]);
    }

    /**
     * Получить конкретный триггер
     */
    public function show(BuyTrigger $trigger): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $trigger->load(['cryptoCurrency', 'country', 'bank'])
        ]);
    }

    /**
     * Создать новый триггер
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'crypto_currency_id' => 'required|exists:crypto_currencies,id',
            'wallet_address' => 'required|string|max:50',
            'buy_currency_type' => 'required|in:fiat,crypto',
            'buy_currency_id' => 'required|integer',
            'buy_amount' => 'required|numeric|min:0',
            'sell_currency_type' => 'required|in:fiat,crypto',
            'sell_currency_id' => 'required|integer',
            'max_sell_amount' => 'nullable|numeric|min:0',
            'country_id' => 'nullable|exists:countries,id',
            'bank_id' => 'nullable|exists:banks,id',
            'is_for_individuals' => 'boolean',
            'is_for_legal_entities' => 'boolean',
            'is_test' => 'boolean',
            'test_group' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $trigger = BuyTrigger::create($validator->validated());

        return response()->json([
            'success' => true,
            'data' => $trigger->load(['cryptoCurrency', 'country', 'bank'])
        ], 201);
    }

    /**
     * Обновить триггер
     */
    public function update(Request $request, BuyTrigger $trigger): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'is_active' => 'boolean',
            'max_sell_amount' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $trigger->update($validator->validated());

        return response()->json([
            'success' => true,
            'data' => $trigger->load(['cryptoCurrency', 'country', 'bank'])
        ]);
    }

    /**
     * Удалить триггер
     */
    public function destroy(BuyTrigger $trigger): JsonResponse
    {
        $trigger->delete();

        return response()->json([
            'success' => true,
            'message' => 'Триггер успешно удален'
        ]);
    }
} 