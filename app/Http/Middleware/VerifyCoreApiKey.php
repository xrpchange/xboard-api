<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates Core → Laravel internal callbacks (X-Core-Key).
 * Accepts either sandbox or production Core key configured in services.php.
 */
class VerifyCoreApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKeys = array_values(array_filter([
            (string) config('services.core.key', ''),
            (string) config('services.core_production.key', ''),
        ]));

        if ($expectedKeys === []) {
            return response()->json([
                'success' => false,
                'message' => 'Core callback authentication is not configured (CORE_KEY).',
                'error_code' => 'CONFIG_MISSING',
            ], 500);
        }

        $sent = trim((string) $request->header('X-Core-Key', ''));
        foreach ($expectedKeys as $expected) {
            if ($expected !== '' && hash_equals($expected, $sent)) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Forbidden — invalid or missing X-Core-Key.',
            'error_code' => 'FORBIDDEN',
        ], 403);
    }
}
