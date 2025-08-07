<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Получить Bearer токен по залоговому токену
     */
    public function getBearerToken(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'escrow_token' => 'required|string|max:500',
            'test_group' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Здесь будет интеграция с ядром для валидации залогового токена
        // Пока создаем простую валидацию
        $escrowToken = $request->escrow_token;
        
        // Проверяем формат токена (должен содержать ID криптовалюты и адрес кошелька)
        if (!$this->validateEscrowToken($escrowToken)) {
            return response()->json([
                'success' => false,
                'message' => 'Неверный формат залогового токена'
            ], 401);
        }

        // Генерируем Bearer токен
        $bearerToken = $this->generateBearerToken($escrowToken, $request->test_group);

        return response()->json([
            'success' => true,
            'data' => [
                'bearer_token' => $bearerToken,
                'expires_at' => now()->addYear(),
                'test_group' => $request->test_group
            ]
        ]);
    }

    /**
     * Валидация залогового токена
     */
    private function validateEscrowToken(string $token): bool
    {
        // Простая проверка - токен должен содержать определенную структуру
        // В реальности здесь будет интеграция с ядром
        return !empty($token) && strlen($token) > 10;
    }

    /**
     * Генерация Bearer токена
     */
    private function generateBearerToken(string $escrowToken, ?string $testGroup): string
    {
        // Создаем хэш на основе залогового токена и тестовой группы
        $data = $escrowToken . ($testGroup ?? '') . time();
        return hash('sha256', $data);
    }

    /**
     * Проверка Bearer токена
     */
    public function validateBearerToken(Request $request): JsonResponse
    {
        $bearerToken = $request->bearerToken();

        if (!$bearerToken) {
            return response()->json([
                'success' => false,
                'message' => 'Bearer токен не предоставлен'
            ], 401);
        }

        // Здесь будет проверка токена
        // Пока простая проверка
        if (strlen($bearerToken) !== 64) {
            return response()->json([
                'success' => false,
                'message' => 'Неверный формат Bearer токена'
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'valid' => true,
                'test_group' => $request->header('X-Test-Group')
            ]
        ]);
    }
} 