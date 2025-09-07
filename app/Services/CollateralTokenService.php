<?php

namespace App\Services;

use App\Models\CryptoCurrency;
use App\Models\ExchangeLot;
use App\Models\UserToken;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class CollateralTokenService
{
    public function __construct() {}

    /**
     * Проверить баланс залогового токена через простое обращение к ядру
     */
    public function checkTokenBalance(string $token): array
    {
        $coreUrl = config('services.core.url');
        $coreKey = config('services.core.key');
        $timeout = config('services.core.timeout', 10);

        try {
            $request = Http::timeout($timeout);
            
            if (!empty($coreKey)) {
                $request = $request->withHeaders([
                    'X-Core-Key' => $coreKey,
                ]);
            }

            $response = $request->post($coreUrl . '/api/tokens/balance', [
                'token' => $token,
            ]);

            if (!$response->successful()) {
                throw new \Exception('Core request failed with status: ' . $response->status());
            }

            $data = $response->json();
            
            if (!isset($data['total'])) {
                Log::warning('Token balance response missing total');
            }
            
            return $data;
        } catch (\Exception $e) {
            Log::error('Token balance check failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Расшифровать залоговый токен и получить данные
     */
    public function decryptToken(string $token): array
    {
        try {
            $tokenData = json_decode($token, true);
            
            if (!isset($tokenData['version'], $tokenData['wallet_address'], $tokenData['currency'])) {
                throw new \Exception('Invalid token structure');
            }

            if ($tokenData['currency'] !== 'XRP') {
                throw new \Exception('Unsupported currency: ' . $tokenData['currency']);
            }

            return [
                'wallet_address' => $tokenData['wallet_address'],
                'currency' => $tokenData['currency'],
                'mode' => $tokenData['mode'] ?? 'prod'
            ];
        } catch (\Exception $e) {
            Log::error('Token decryption failed', [
                'token' => substr($token, 0, 50) . '...',
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить или создать лот обмена на основе залогового токена
     */
    public function getOrCreateExchangeLot(string $walletAddress, string $currency = 'XRP'): ExchangeLot
    {
        // Найти криптовалюту
        $cryptoCurrency = CryptoCurrency::where('code', $currency)->first();
        if (!$cryptoCurrency) {
            throw new \Exception('Unsupported currency: ' . $currency);
        }

        // Найти или создать лот обмена
        $exchangeLot = ExchangeLot::where('wallet_address', $walletAddress)
            ->where('crypto_currency_id', $cryptoCurrency->id)
            ->first();

        if (!$exchangeLot) {
            $exchangeLot = ExchangeLot::create([
                'wallet_address' => $walletAddress,
                'crypto_currency_id' => $cryptoCurrency->id,
                'is_test' => false, // Будет определяться из токена
                'test_group' => null
            ]);
        }

        return $exchangeLot;
    }

    /**
     * Создать Bearer токен после успешной проверки баланса
     */
    public function createBearerToken(string $collateralToken, string $ipAddress): string
    {
        // Расшифровать залоговый токен
        $tokenData = $this->decryptToken($collateralToken);
        
        // Проверить баланс через ядро
        $balanceData = $this->checkTokenBalance($collateralToken);
        
        // Получить или создать лот обмена
        $exchangeLot = $this->getOrCreateExchangeLot($tokenData['wallet_address'], $tokenData['currency']);
        
        // Сгенерировать уникальный токен
        $bearerToken = hash('sha256', $collateralToken . $ipAddress . time() . random_bytes(32));
        
        // Сохранить токен в БД
        UserToken::create([
            'token' => $bearerToken,
            'device_id' => $tokenData['wallet_address'], // Используем адрес кошелька как device_id
            'collateral_token' => $collateralToken,
            'ip_address' => $ipAddress,
            'expires_at' => now()->addYear()
        ]);

        return $bearerToken;
    }

    /**
     * Валидировать Bearer токен
     */
    public function validateBearerToken(string $bearerToken): ?UserToken
    {
        $userToken = UserToken::where('token', $bearerToken)->first();
        
        if (!$userToken || $userToken->isExpired()) {
            return null;
        }

        // Обновить время последнего использования
        $userToken->updateLastUsed();
        
        return $userToken;
    }
}
