<?php

namespace App\Services;

use App\Models\CryptoCurrency;
use App\Models\ExchangeLot;
use App\Models\UserToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CollateralTokenService
{
    private array $coreConfig;

    public function __construct()
    {
        $this->coreConfig = config('services.core', []);
    }

    /**
     * Определить окружение по домену запроса
     */
    private function determineEnvironment(): string
    {
        $host = request()->getHost();
        
        // Если домен xrpchange.site
        if (str_contains($host, 'xrpchange.site')) {
            // Если содержит sandbox - песочница для клиентов
            if (str_contains($host, 'sandbox')) {
                return 'sandbox';
            }
            // Иначе - тестовая среда для разработчика
            return 'development';
        }
        
        // Если домен не xrpchange.site - продакшен
        return 'production';
    }

    /**
     * Проверить баланс залогового токена через ядро с отказоустойчивостью
     */
    public function checkTokenBalance(string $token, string $environment = null): array
    {
        $environment = $environment ?? $this->determineEnvironment();
        $servers = $this->coreConfig['servers'][$environment] ?? null;
        
        if (!$servers) {
            throw new \Exception("Unknown core environment: {$environment}");
        }

        $timeout = $this->coreConfig['timeout'] ?? 10;
        $retryAttempts = $this->coreConfig['retry_attempts'] ?? 3;
        $lastException = null;

        // Для development используем только core, для остальных - только core1, core2, core3
        if ($environment === 'development') {
            $serverPriority = ['core'];
        } else {
            $serverPriority = ['core1', 'core2', 'core3'];
        }
        
        $availableServers = array_intersect($serverPriority, array_keys($servers));
        
        foreach ($availableServers as $serverName) {
            $serverConfig = $servers[$serverName];
            
            for ($attempt = 1; $attempt <= $retryAttempts; $attempt++) {
                try {
                    $response = Http::timeout($timeout)->post($serverConfig['url'] . '/api/tokens/balance', [
                        'token' => $token
                    ]);

                    if ($response->successful()) {
                        $data = $response->json();
                        if (isset($data['total'])) {
                            Log::info('Token balance check successful', [
                                'server' => $serverName,
                                'environment' => $environment,
                                'attempt' => $attempt
                            ]);
                            return $data;
                        }
                    }
                } catch (\Exception $e) {
                    $lastException = $e;
                    Log::warning('Token balance check attempt failed', [
                        'server' => $serverName,
                        'environment' => $environment,
                        'attempt' => $attempt,
                        'error' => $e->getMessage()
                    ]);
                    
                    // Если это не последняя попытка, ждем перед повтором
                    if ($attempt < $retryAttempts) {
                        sleep(1);
                    }
                }
            }
        }

        // Все серверы недоступны
        Log::error('All core servers failed', [
            'environment' => $environment,
            'servers' => $availableServers
        ]);
        
        throw new \Exception('All core servers are unavailable', 0, $lastException);
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
        
        // Проверить баланс через ядро (окружение определяется конфигурацией приложения)
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
