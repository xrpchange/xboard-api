<?php

namespace App\Services;

use App\Exceptions\InvalidTokenException;
use App\Exceptions\UnsupportedCurrencyException;
use App\Models\Node;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class EscrowTokenService
{
    public function __construct() {}

    private function normalizeCoreBaseUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    /**
     * One key per logical Core base so allowlist matches client and DB even if one side uses a trailing "/api".
     */
    private function canonicalCoreBaseKey(string $url): ?string
    {
        $trimmed = rtrim(trim($url), '/');
        $parts = parse_url($trimmed);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        $path = isset($parts['path']) ? str_replace('\\', '/', (string) $parts['path']) : '';
        $path = rtrim($path, '/');
        if ($path === '/') {
            $path = '';
        }
        while ($path !== '' && str_ends_with(strtolower($path), '/api')) {
            $path = rtrim(substr($path, 0, -strlen('/api')), '/');
        }
        if ($path === '' || $path === '/') {
            return 'https://'.$host;
        }

        return 'https://'.$host.$path;
    }

    /**
     * @return array<string, true>
     */
    private function registeredCoreBaseKeysFromActiveNodes(): array
    {
        $keys = [];
        foreach (Node::query()->where('is_active', true)->cursor() as $node) {
            $endpoints = $node->endpoints;
            if (! is_array($endpoints)) {
                continue;
            }
            foreach ($endpoints as $name => $value) {
                if (! is_string($name)) {
                    continue;
                }
                if (! str_starts_with($name, 'core_') && $name !== 'core_api') {
                    continue;
                }
                if (is_array($value)) {
                    $value = $value['url'] ?? null;
                }
                if (! is_string($value) || trim($value) === '') {
                    continue;
                }
                $k = $this->canonicalCoreBaseKey($value);
                if ($k !== null) {
                    $keys[$k] = true;
                }
            }
        }

        return $keys;
    }

    private function clientCoreBaseUrlIsAllowlisted(string $normalized): bool
    {
        $clientKey = $this->canonicalCoreBaseKey($normalized);

        return $clientKey !== null && isset($this->registeredCoreBaseKeysFromActiveNodes()[$clientKey]);
    }

    private function coreBaseAllowlistErrorMessage(string $normalized): string
    {
        $clientKey = $this->canonicalCoreBaseKey($normalized) ?? 'invalid';
        $registered = array_keys($this->registeredCoreBaseKeysFromActiveNodes());
        sort($registered);

        return 'core_base_url is not registered for any active exchange node'
            . ' (client=' . $clientKey
            . ', registered=' . implode(',', $registered) . ')';
    }

    /**
     * Client sends the same Core base URL it uses for get-encrypted-part (HTTPS; must match nodes.endpoints core_* canonically).
     */
    private function assertClientCoreBaseUrlShape(string $normalized): void
    {
        if ($normalized === '') {
            throw new \InvalidArgumentException('core_base_url is empty');
        }
        $parts = parse_url($normalized);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('core_base_url is not a valid URL');
        }
        if (strcasecmp((string) $parts['scheme'], 'https') !== 0) {
            throw new \InvalidArgumentException('core_base_url must use HTTPS');
        }
    }

    /**
     * @return array{0: string, 1: string} [coreBaseUrl, xCoreKey]
     */
    private function resolveCoreUrlAndKey(Request $request, string $coreBaseUrlFromClient): array
    {
        $host = $request->getHost();
        $useProductionConfig = ! preg_match('/^(sandbox\.|dev\.)/', $host);
        $defaultKey = (string) ($useProductionConfig ? config('services.core_production.key') : config('services.core.key'));

        $trimmed = trim($coreBaseUrlFromClient);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('core_base_url is required');
        }

        $normalized = $this->normalizeCoreBaseUrl($trimmed);
        $this->assertClientCoreBaseUrlShape($normalized);
        if (! $this->clientCoreBaseUrlIsAllowlisted($normalized)) {
            throw new \InvalidArgumentException($this->coreBaseAllowlistErrorMessage($normalized));
        }

        return [$normalized, $defaultKey];
    }

    /**
     * Create escrow token through core API
     * Stage 1: Get open part of token
     *
     * @param  Request  $request  Use the same host the controller sees (TrustedProxies / X-Forwarded-Host),
     *                             not raw {@see $_SERVER['HTTP_HOST']}, so sandbox vs production Core URL matches the public API host.
     * @param  string  $coreBaseUrlFromClient  Required; same Core base the client uses for get-encrypted-part (allowlisted).
     */
    public function createToken(
        Request $request,
        string $cryptoType,
        float $amount = 0.0,
        ?string $network = null,
        bool $escrowMultisig2Of3Required = false,
        string $coreBaseUrlFromClient
    ): array {
        [$coreUrl, $coreKey] = $this->resolveCoreUrlAndKey($request, $coreBaseUrlFromClient);

        $timeout = config('services.core.timeout', 10);

        try {
            $http = Http::timeout($timeout);

            if (!empty($coreKey)) {
                $http = $http->withHeaders([
                    'X-Core-Key' => $coreKey,
                ]);
            }

            $body = [
                'crypto_type' => $cryptoType,
                'amount' => $amount,
            ];
            $networkNorm = $network !== null ? strtolower(trim($network)) : '';
            if ($networkNorm !== '') {
                $body['network'] = $networkNorm;
            }
            if ($escrowMultisig2Of3Required) {
                $body['escrow_multisig_2_of_3_required'] = true;
            }

            $response = $http->post($coreUrl . '/api/escrow-tokens/create', $body);

            if (!$response->successful()) {
                $errorData = $response->json();
                $errorMessage = $errorData['message'] ?? 'Token creation failed';
                throw new \Exception($errorMessage);
            }

            return $response->json();
        } catch (\Throwable $e) {
            throw new \Exception('Failed to create escrow token: ' . $e->getMessage());
        }
    }

    /**
     * Validate escrow token through core using token_hash
     */
    public function validateToken(string $token): array
    {
        $coreUrl = config('services.core.url');
        $coreKey = config('services.core.key');
        $timeout = config('services.core.timeout', 10);

        // Decode token to get token_hash and wallet_address
        $tokenDecoded = json_decode($token, true);
        if (!$tokenDecoded || !isset($tokenDecoded['token_hash']) || !isset($tokenDecoded['wallet_address'])) {
            throw new InvalidTokenException('Invalid token format: missing token_hash or wallet_address');
        }

        try {
            $request = Http::timeout($timeout);
            
            if (!empty($coreKey)) {
                $request = $request->withHeaders([
                    'X-Core-Key' => $coreKey,
                ]);
            }

            // Валидация через новый endpoint /api/escrow-tokens/validate
            $response = $request->post($coreUrl . '/api/escrow-tokens/validate', [
                'token_hash' => $tokenDecoded['token_hash'],
            ]);

            if (!$response->successful()) {
                throw new InvalidTokenException('Token is invalid or unavailable');
            }

            $data = $response->json();
            
            // Проверяем, что токен валиден
            if (!isset($data['valid']) || $data['valid'] !== true) {
                throw new InvalidTokenException('Token validation failed');
            }
        } catch (\Throwable $e) {
            throw new InvalidTokenException('Token is invalid or unavailable: ' . $e->getMessage());
        }
        
        return [
            'wallet_address' => $tokenDecoded['wallet_address'],
            'token_hash' => $tokenDecoded['token_hash'],
            'crypto_type' => $data['data']['crypto_type'] ?? null,
            'expires_at' => $data['data']['expires_at'] ?? null
        ];
    }

    /**
     * Get available cryptocurrencies from Core API
     */
    public function getAvailableCryptos(): array
    {
        // Determine Core URL based on current API host (preserve environment)
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $coreUrl = config('services.core.url'); // default (sandbox)
        
        // If request came to production API, use production Core
        if (!preg_match('/^(sandbox\.|dev\.)/', $host)) {
            $coreUrl = config('services.core_production.url');
        }
        
        $coreKey = config('services.core.key');
        $timeout = config('services.core.timeout', 10);

        try {
            $request = Http::timeout($timeout);
            
            if (!empty($coreKey)) {
                $request = $request->withHeaders([
                    'X-Core-Key' => $coreKey,
                ]);
            }

            $response = $request->get($coreUrl . '/api/info');

            if (!$response->successful()) {
                throw new \Exception('Failed to get cryptocurrencies from Core');
            }

            return $response->json();
        } catch (\Throwable $e) {
            throw new \Exception('Failed to get available cryptocurrencies: ' . $e->getMessage());
        }
    }

    /**
     * Get crypto currency ID from token
     */
    public function getCryptoCurrencyId(string $token): int
    {
        $tokenDecoded = json_decode($token, true);
        
        if (!$tokenDecoded || !isset($tokenDecoded['currency'])) {
            throw new InvalidTokenException('Invalid token format');
        }
        
        // Get crypto currency ID by code (case-insensitive; Core sends e.g. xrp, usdt_stellar)
        $code = strtolower((string) $tokenDecoded['currency']);
        $cryptoCurrency = DB::table('crypto_currencies')
            ->whereRaw('LOWER(code) = ?', [$code])
            ->where('is_active', true)
            ->first();
            
        if (!$cryptoCurrency) {
            throw new UnsupportedCurrencyException($tokenDecoded['currency']);
        }
        
        return $cryptoCurrency->id;
    }
}
