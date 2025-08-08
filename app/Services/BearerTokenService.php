<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BearerTokenService
{
    /**
     * Генерация Bearer токена
     */
    public function generateToken(string $escrowToken): string
    {
        $data = $escrowToken . time() . Str::random(32);
        return hash('sha256', $data);
    }

    /**
     * Сохранение Bearer токена в таблице user_tokens
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
     * Проверка Bearer токена
     */
    public function validateToken(string $bearerToken): ?object
    {
        $tokenRecord = DB::table('user_tokens')
            ->where('token', $bearerToken)
            ->where('expires_at', '>', now())
            ->first();

        if ($tokenRecord) {
            // Обновляем время последнего использования
            DB::table('user_tokens')
                ->where('id', $tokenRecord->id)
                ->update(['last_used_at' => now()]);
        }

        return $tokenRecord;
    }
}







