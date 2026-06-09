<?php

namespace App\Services\Arbiter;

use App\Services\Arbiter\Contracts\ArbiterProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Sber GigaChat: OAuth access token + OpenAI-compatible chat completions.
 *
 * @see https://developers.sber.ru/docs/ru/gigachat/api/reference/rest/gigachat-api
 * @see https://developers.sber.ru/docs/ru/gigachat/guides/compatible-openai
 */
class GigaChatArbiterProvider implements ArbiterProvider
{
    public function __construct(
        private readonly string $oauthCredential,
        private readonly string $scope,
        private readonly string $oauthUrl,
        private readonly string $apiBaseUrl,
        private readonly string $model,
        private readonly int $timeoutSeconds,
        private readonly bool $verifySsl
    ) {}

    public function providerId(): string
    {
        return 'gigachat';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function generate(array $payload): array
    {
        if (trim($this->oauthCredential) === '') {
            throw new \RuntimeException('GigaChat OAuth credential is not configured.');
        }

        $response = $this->postChatCompletion($payload, $this->getCachedAccessToken());

        if ($response->status() === 401) {
            $this->forgetCachedAccessToken();
            $response = $this->postChatCompletion($payload, $this->fetchAndCacheAccessToken(
                $this->accessTokenCacheKey()
            ));
        }

        if (! $response->successful()) {
            $snippet = mb_substr(trim($response->body()), 0, 240);
            $detail = $snippet !== '' ? ' body='.$snippet : '';

            throw new \RuntimeException('GigaChat request failed with status '.$response->status().$detail);
        }

        $json = $response->json();
        $text = (string) ($json['choices'][0]['message']['content'] ?? '');
        if (trim($text) === '') {
            throw new \RuntimeException('GigaChat returned empty response.');
        }

        return [
            'raw_text' => $text,
            'provider' => $this->providerId(),
            'model' => $this->model,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postChatCompletion(array $payload, string $accessToken): \Illuminate\Http\Client\Response
    {
        $url = rtrim($this->apiBaseUrl, '/').'/chat/completions';

        $request = Http::timeout($this->timeoutSeconds)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->withToken($accessToken);

        if (! $this->verifySsl) {
            $request = $request->withoutVerifying();
        }

        return $request->post($url, [
            'model' => $this->model,
            'temperature' => 0,
            'max_tokens' => 350,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => (string) ($payload['system_prompt'] ?? ''),
                ],
                [
                    'role' => 'user',
                    'content' => (string) ($payload['user_prompt'] ?? ''),
                ],
            ],
        ]);
    }

    private function accessTokenCacheKey(): string
    {
        return 'arbiter:gigachat:access:'.hash('sha256', $this->oauthCredential);
    }

    private function forgetCachedAccessToken(): void
    {
        Cache::forget($this->accessTokenCacheKey());
    }

    private function getCachedAccessToken(): string
    {
        $cacheKey = $this->accessTokenCacheKey();

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->fetchAndCacheAccessToken($cacheKey);
    }

    private function fetchAndCacheAccessToken(string $cacheKey): string
    {
        $authHeader = $this->normalizeOAuthAuthorizationHeader();

        $oauthClient = Http::timeout($this->timeoutSeconds)
            ->asForm()
            ->withHeaders([
                'Accept' => 'application/json',
                'RqUID' => (string) Str::uuid(),
                'Authorization' => $authHeader,
            ]);

        if (!$this->verifySsl) {
            $oauthClient = $oauthClient->withoutVerifying();
        }

        $response = $oauthClient->post($this->oauthUrl, ['scope' => $this->scope]);

        if (!$response->successful()) {
            throw new \RuntimeException('GigaChat OAuth failed with status ' . $response->status());
        }

        $json = $response->json();
        $token = (string) ($json['access_token'] ?? '');
        if ($token === '') {
            throw new \RuntimeException('GigaChat OAuth returned no access_token.');
        }

        $ttlSeconds = self::accessTokenCacheTtlSeconds($json);

        Cache::put($cacheKey, $token, $ttlSeconds);

        return $token;
    }

    /**
     * Sber accepts either "Basic <base64>" or (in some SDK examples) "Bearer <authorization key>".
     */
    private function normalizeOAuthAuthorizationHeader(): string
    {
        $raw = trim($this->oauthCredential);
        if (str_starts_with($raw, 'Basic ') || str_starts_with($raw, 'Bearer ')) {
            return $raw;
        }

        return 'Basic ' . $raw;
    }

    /**
     * @param  array<string, mixed>  $oauthJson
     */
    public static function accessTokenCacheTtlSeconds(array $oauthJson): int
    {
        $expiresRaw = (int) ($oauthJson['expires_at'] ?? 0);
        if ($expiresRaw <= 0) {
            return 1200;
        }
        // Sber may return expires_at in seconds or milliseconds.
        $expiresAtSeconds = $expiresRaw > 9_999_999_999
            ? (int) floor($expiresRaw / 1000)
            : $expiresRaw;
        $ttl = $expiresAtSeconds - time() - 90;

        return max(120, min($ttl, 1800));
    }
}
