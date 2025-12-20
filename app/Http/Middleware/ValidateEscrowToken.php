<?php

namespace App\Http\Middleware;

use App\Services\EscrowTokenValidationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

/**
 * Middleware to validate escrow token (publicPart) from request
 * 
 * Simplified authentication - no Bearer tokens, no expiration checks.
 * Just validates publicPart via cache/Core API.
 * 
 * This approach significantly simplifies the codebase and makes future migrations easier.
 * 
 * Expects token in:
 * - Header: X-Escrow-Token (preferred)
 * - Or: Request body/query parameter: escrow_token
 * 
 * Validates token via cache/Core API and attaches token data to request
 */
class ValidateEscrowToken
{
    public function __construct(
        private EscrowTokenValidationService $tokenValidationService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Log::debug('ValidateEscrowToken middleware: Request received', [
            'path' => $request->path(),
            'method' => $request->method(),
            'has_x_escrow_token' => $request->hasHeader('X-Escrow-Token'),
            'ip' => $request->ip()
        ]);

        // Get token from header or request
        $publicPart = $request->header('X-Escrow-Token') 
                   ?? $request->input('escrow_token')
                   ?? $request->query('escrow_token');

        if (!$publicPart) {
            Log::warning('ValidateEscrowToken middleware: Missing token', [
                'path' => $request->path(),
                'headers' => array_keys($request->headers->all())
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Escrow token (publicPart) required. Send in X-Escrow-Token header or escrow_token parameter.'
            ], 401);
        }

        // Extract token_hash from publicPart for logging
        $tokenData = json_decode($publicPart, true);
        $tokenHashPreview = isset($tokenData['token_hash']) ? substr($tokenData['token_hash'], 0, 16) . '...' : 'missing';
        
        Log::debug('ValidateEscrowToken middleware: Token found, starting validation', [
            'token_hash' => $tokenHashPreview,
            'token_length' => strlen($publicPart)
        ]);

        try {
            // Validate token
            $validation = $this->tokenValidationService->validateToken(
                $publicPart,
                $request->header('X-Device-ID'),
                $request->ip()
            );

            Log::debug('ValidateEscrowToken middleware: Validation result', [
                'valid' => $validation['valid'] ?? false,
                'has_error' => isset($validation['error']),
                'error' => $validation['error'] ?? null
            ]);

            if (!$validation['valid']) {
                // Extract token_hash from publicPart for logging
                $tokenData = json_decode($publicPart, true);
                $tokenHashPreview = isset($tokenData['token_hash']) ? substr($tokenData['token_hash'], 0, 16) . '...' : 'missing';
                
                Log::warning('Escrow token validation failed in middleware', [
                    'token_hash' => $tokenHashPreview,
                    'error' => $validation['error'] ?? 'Unknown error',
                    'ip' => $request->ip()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid escrow token: ' . ($validation['error'] ?? 'Validation failed')
                ], 401);
            }

            Log::info('ValidateEscrowToken middleware: Token validated successfully, proceeding to controller', [
                'path' => $request->path()
            ]);

            // Attach token data to request for use in controllers
            $request->merge([
                'escrow_token_data' => $validation['token_data'],
                'escrow_token_cache' => $validation['cache']
            ]);

            return $next($request);
        } catch (\Exception $e) {
            Log::error('ValidateEscrowToken middleware: Exception during validation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Token validation error: ' . $e->getMessage()
            ], 500);
        }
    }
}

