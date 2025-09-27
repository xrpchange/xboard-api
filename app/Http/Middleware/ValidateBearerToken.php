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
                'message' => 'Bearer token not provided'
            ], 401);
        }

        // Validate Bearer token via service
        $userToken = $this->collateralTokenService->validateBearerToken($bearerToken);
        
        if (!$userToken) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid bearer token'
            ], 401);
        }

        // Add token information to request
        $request->merge([
            'user_token' => $userToken,
            'wallet_address' => $userToken->device_id
        ]);

        // Add test group information to request
        $request->merge(['test_group' => $request->header('X-Test-Group')]);

        return $next($request);
    }


} 