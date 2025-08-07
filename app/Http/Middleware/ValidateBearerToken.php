<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateBearerToken
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (!$bearerToken) {
            return response()->json([
                'success' => false,
                'message' => 'Bearer токен не предоставлен'
            ], 401);
        }

        // Проверяем формат токена
        if (strlen($bearerToken) !== 64) {
            return response()->json([
                'success' => false,
                'message' => 'Неверный формат Bearer токена'
            ], 401);
        }

        // Здесь будет интеграция с ядром для проверки токена
        // Пока простая проверка
        if (!$this->isValidToken($bearerToken)) {
            return response()->json([
                'success' => false,
                'message' => 'Недействительный Bearer токен'
            ], 401);
        }

        // Добавляем информацию о тестовой группе в request
        $request->merge(['test_group' => $request->header('X-Test-Group')]);

        return $next($request);
    }

    /**
     * Проверка валидности токена
     */
    private function isValidToken(string $token): bool
    {
        // В реальности здесь будет проверка через ядро
        // Пока простая проверка
        return !empty($token) && ctype_xdigit($token);
    }
} 