<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BearerTokenService
{
    /**
     * Generate Bearer token
     */
    public function generateToken(string $escrowToken): string
    {
        $data = $escrowToken . time() . Str::random(32);
        return hash('sha256', $data);
    }

    /**
     * Save Bearer token to user_tokens table
     */
    public function saveToken(string $bearerToken, string $escrowToken, string $ipAddress): void
    {
        $deviceId = hash('sha256', $ipAddress . time() . Str::random(16));
        
        DB::table('user_tokens')->insert([
            'token' => $bearerToken,
            'device_id' => $deviceId,
            'collateral_token' => $escrowToken,
            'ip_address' => $ipAddress,
            'expires_at' => now()->addYear(),
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    /**
     * Validate Bearer token
     */
    public function validateToken(string $bearerToken): ?object
    {
        $tokenRecord = DB::table('user_tokens')
            ->where('token', $bearerToken)
            ->where('expires_at', '>', now())
            ->first();

        if ($tokenRecord) {
            // Update last used time
            DB::table('user_tokens')
                ->where('id', $tokenRecord->id)
                ->update(['last_used_at' => now()]);
        }

        return $tokenRecord;
    }
}









