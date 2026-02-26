<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAccess
{
    /**
     * Handle an incoming request.
     * Requires valid admin key (X-Admin-Key header or Bearer token) from config.
     * Used together with escrow.token for admin routes.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $adminKey = config('services.admin.key');
        if (empty($adminKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Admin access not configured'
            ], 503);
        }

        $provided = $request->header('X-Admin-Key') ?? $request->bearerToken();
        if (empty($provided) || !hash_equals($adminKey, $provided)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden - Admin access required'
            ], 403);
        }

        return $next($request);
    }
}






