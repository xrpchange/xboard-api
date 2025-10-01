<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\UserToken;
use Symfony\Component\HttpFoundation\Response;

class AdminAccess
{
    /**
     * Handle an incoming request.
     * Проверяет, что пользователь имеет роль admin
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (!$bearerToken) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Bearer token required'
            ], 401);
        }

        // Получаем токен из БД
        $userToken = UserToken::where('token', $bearerToken)->first();

        if (!$userToken || $userToken->isExpired()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Invalid or expired token'
            ], 401);
        }

        // Проверяем роль (если поле role еще не добавлено, используем временное решение)
        $userRole = $userToken->role ?? 'public';

        if ($userRole !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden - Admin access required'
            ], 403);
        }

        return $next($request);
    }
}






