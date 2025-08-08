<?php

namespace App\Http\Middleware;

use App\Services\CollateralTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateBearerToken
{
    public function __construct(
        private CollateralTokenService $collateralTokenService
    ) {}

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

        // Валидировать Bearer токен через сервис
        $userToken = $this->collateralTokenService->validateBearerToken($bearerToken);
        
        if (!$userToken) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid bearer token'
            ], 401);
        }

        // Добавляем информацию о токене в request
        $request->merge([
            'user_token' => $userToken,
            'wallet_address' => $userToken->device_id
        ]);

        // Добавляем информацию о тестовой группе в request
        $request->merge(['test_group' => $request->header('X-Test-Group')]);

        return $next($request);
    }


} 