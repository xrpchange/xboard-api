<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CoreClient
{
    private array $coreConfig;
    private array $memoryCache = []; // Простой кэш в памяти

    public function __construct()
    {
        $this->coreConfig = config('services.core', []);
    }

    /**
     * Определить окружение по домену запроса
     */
    public function determineEnvironment(): string
    {
        $defaultEnvironment = $this->coreConfig['default_environment'] ?? 'development';
        $host = request()->getHost();

        if (!$host) {
            return $defaultEnvironment;
        }

        if (str_contains($host, 'xrpchange.site')) {
            if (str_contains($host, 'sandbox')) {
                return 'sandbox';
            }
            return 'development';
        }

        return 'production';
    }

    /**
     * Собрать список серверов ядра для окружения в виде массива элементов
     * [ ['name' => 'core1', 'url' => '...', 'key' => '...'], ... ]
     */
    private function getServers(string $environment): array
    {
        $root = $this->coreConfig['servers'][$environment] ?? [];
        if (empty($root)) {
            return [];
        }

        // Рекурсивно извлекаем все узлы, у которых есть 'url'
        $result = [];

        $walker = function ($node, array $path = []) use (&$walker, &$result) {
            if (is_array($node) && Arr::has($node, ['url'])) {
                $name = implode('.', $path) ?: 'core';
                $result[] = [
                    'name' => $name,
                    'url' => $node['url'],
                    'key' => $node['key'] ?? null,
                ];
                return;
            }

            if (is_array($node)) {
                foreach ($node as $key => $value) {
                    $walker($value, array_merge($path, [$key]));
                }
            }
        };

        $walker($root, []);

        return $result;
    }

    /**
     * Сформировать список индексов серверов для обхода:
     * 1) сначала кэшированный индекс (если валиден),
     * 2) затем остальные в случайном порядке
     */
    private function getServerProbeOrder(string $environment, int $serversCount): array
    {
        $indices = range(0, max(0, $serversCount - 1));
        if ($serversCount <= 1) {
            return $indices;
        }

        $cacheKey = $this->makeCacheKey($environment);
        $cachedIndex = $this->memoryCache[$cacheKey] ?? null;

        // Перемешиваем все индексы
        shuffle($indices);

        if (is_int($cachedIndex) && $cachedIndex >= 0 && $cachedIndex < $serversCount) {
            // Ставим кэшированный индекс в начало, сохраняя случайный порядок остальных
            $indices = array_values(array_unique(array_merge([$cachedIndex], $indices)));
        }

        return $indices;
    }

    private function makeCacheKey(string $environment): string
    {
        return 'core:last_success_index:' . $environment;
    }

    private function rememberSuccessfulIndex(string $environment, int $index): void
    {
        $cacheKey = $this->makeCacheKey($environment);
        $this->memoryCache[$cacheKey] = $index;
    }

    /**
     * Универсальный вызов к ядру с отказоустойчивостью.
     * Возвращает массив JSON-ответа при успехе, бросает исключение при полной недоступности.
     */
    public function requestJson(string $httpMethod, string $path, array $payload = [], ?string $environment = null): array
    {
        $environment = $environment ?: $this->determineEnvironment();
        $servers = $this->getServers($environment);
        if (empty($servers)) {
            throw new \RuntimeException("Unknown or empty core environment: {$environment}");
        }

        $timeout = (int) ($this->coreConfig['timeout'] ?? 10);
        $retryAttempts = (int) ($this->coreConfig['retry_attempts'] ?? 3);
        $order = $this->getServerProbeOrder($environment, count($servers));

        $lastException = null;

        foreach ($order as $serverIndex) {
            $server = $servers[$serverIndex];

            for ($attempt = 1; $attempt <= $retryAttempts; $attempt++) {
                try {
                    $request = Http::timeout($timeout);

                    // При необходимости можно добавить ключ авторизации заголовком
                    if (!empty($server['key'])) {
                        $request = $request->withHeaders([
                            'X-Core-Key' => $server['key'],
                        ]);
                    }

                    $url = rtrim($server['url'], '/') . '/' . ltrim($path, '/');

                    $response = match (strtoupper($httpMethod)) {
                        'GET' => $request->get($url, $payload),
                        'POST' => $request->post($url, $payload),
                        'PUT' => $request->put($url, $payload),
                        'PATCH' => $request->patch($url, $payload),
                        'DELETE' => $request->delete($url, $payload),
                        default => throw new \InvalidArgumentException('Unsupported HTTP method: ' . $httpMethod),
                    };

                    if ($response->successful()) {
                        $this->rememberSuccessfulIndex($environment, $serverIndex);
                        return $response->json();
                    }

                    // Неположительный ответ тоже считаем неуспехом
                    Log::warning('Core request non-success response', [
                        'env' => $environment,
                        'server' => $server['name'],
                        'status' => $response->status(),
                        'url' => $url,
                        'attempt' => $attempt,
                    ]);
                } catch (\Throwable $e) {
                    $lastException = $e;
                    Log::warning('Core request failed', [
                        'env' => $environment,
                        'server' => $server['name'],
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($attempt < $retryAttempts) {
                    usleep(250_000); // небольшая задержка между повторами
                }
            }
        }

        Log::error('All core servers failed', [
            'environment' => $environment,
            'path' => $path,
        ]);

        throw new \RuntimeException('All core servers are unavailable', 0, $lastException);
    }

    /**
     * Health-check всех серверов окружения. Возвращает массив статусов.
     */
    public function healthCheck(?string $environment = null): array
    {
        $environment = $environment ?: $this->determineEnvironment();
        $servers = $this->getServers($environment);
        $healthPath = $this->coreConfig['health_path'] ?? '/echo';
        $timeout = (int) ($this->coreConfig['timeout'] ?? 10);

        $results = [];
        foreach ($servers as $index => $server) {
            $url = rtrim($server['url'], '/') . '/' . ltrim($healthPath, '/');
            try {
                $request = Http::timeout($timeout);
                if (!empty($server['key'])) {
                    $request = $request->withHeaders([
                        'X-Core-Key' => $server['key'],
                    ]);
                }
                $response = $request->get($url);
                $ok = $response->successful();
                if ($ok) {
                    $this->rememberSuccessfulIndex($environment, $index);
                }
                $results[] = [
                    'name' => $server['name'],
                    'url' => $server['url'],
                    'status' => $response->status(),
                    'ok' => $ok,
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'name' => $server['name'],
                    'url' => $server['url'],
                    'status' => null,
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $results;
    }

    /**
     * Специализированная обёртка: проверка баланса залогового токена
     */
    public function checkTokenBalance(string $collateralToken, ?string $environment = null): array
    {
        return $this->requestJson('POST', '/api/tokens/balance', [
            'token' => $collateralToken,
        ], $environment);
    }
}


