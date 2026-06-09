<?php

namespace App\Console\Commands;

use App\Services\Arbiter\GigaChatArbiterProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Smoke-test the configured sole arbiter LLM (GigaChat) OAuth + chat API.
 */
class ArbiterGigaChatCheckCommand extends Command
{
    protected $signature = 'arbiter:gigachat-check {--clear-cache : Drop cached OAuth token before check}';

    protected $description = 'Verify GigaChat OAuth and a minimal chat completion (primary arbiter provider)';

    public function handle(): int
    {
        $primary = (string) config('services.arbiter.primary_provider', 'gigachat');
        if ($primary !== 'gigachat') {
            $this->warn("ARBITER_PRIMARY_PROVIDER={$primary} (this command tests GigaChat only).");

            return self::FAILURE;
        }

        $cfg = config('services.arbiter.gigachat', []);
        $key = (string) ($cfg['oauth_credential'] ?? '');
        if ($key === '') {
            $this->error('ARBITER_GIGACHAT_AUTHORIZATION_KEY is empty.');

            return self::FAILURE;
        }

        $cacheKey = 'arbiter:gigachat:access:'.hash('sha256', $key);
        if ($this->option('clear-cache')) {
            Cache::forget($cacheKey);
            $this->line('Cleared cached OAuth token.');
        }

        $this->line('primary_provider='.$primary);
        $this->line('model='.($cfg['model'] ?? 'GigaChat'));
        $this->line('scope='.($cfg['scope'] ?? 'GIGACHAT_API_PERS'));
        $this->line('verify_ssl='.(($cfg['verify_ssl'] ?? true) ? 'true' : 'false'));

        $scope = (string) ($cfg['scope'] ?? 'GIGACHAT_API_PERS');
        $oauthUrl = (string) ($cfg['oauth_url'] ?? '');
        $apiBase = rtrim((string) ($cfg['api_base_url'] ?? ''), '/');
        $model = (string) ($cfg['model'] ?? 'GigaChat');
        $timeout = (int) ($cfg['timeout'] ?? 45);
        $verifySsl = (bool) ($cfg['verify_ssl'] ?? true);

        $authHeader = trim($key);
        if (! str_starts_with($authHeader, 'Basic ') && ! str_starts_with($authHeader, 'Bearer ')) {
            $authHeader = 'Basic '.$authHeader;
        }

        $oauthClient = Http::timeout($timeout)->asForm()->withHeaders([
            'Accept' => 'application/json',
            'RqUID' => (string) Str::uuid(),
            'Authorization' => $authHeader,
        ]);
        if (! $verifySsl) {
            $oauthClient = $oauthClient->withoutVerifying();
        }

        $oauthResp = $oauthClient->post($oauthUrl, ['scope' => $scope]);
        $this->line('oauth_http='.$oauthResp->status());
        if (! $oauthResp->successful()) {
            $this->error(mb_substr($oauthResp->body(), 0, 500));

            return self::FAILURE;
        }

        $oauthJson = $oauthResp->json();
        $token = (string) ($oauthJson['access_token'] ?? '');
        $ttl = GigaChatArbiterProvider::accessTokenCacheTtlSeconds(is_array($oauthJson) ? $oauthJson : []);
        $this->line('oauth_token_len='.strlen($token).' cache_ttl_seconds='.$ttl);

        $apiClient = Http::timeout($timeout)->withToken($token)->acceptJson();
        if (! $verifySsl) {
            $apiClient = $apiClient->withoutVerifying();
        }

        $chatResp = $apiClient->post($apiBase.'/chat/completions', [
            'model' => $model,
            'temperature' => 0,
            'max_tokens' => 16,
            'messages' => [
                ['role' => 'user', 'content' => 'Reply with exactly: OK'],
            ],
        ]);
        $this->line('chat_http='.$chatResp->status());
        if (! $chatResp->successful()) {
            $this->error(mb_substr($chatResp->body(), 0, 500));

            return self::FAILURE;
        }

        $preview = trim((string) ($chatResp->json('choices.0.message.content') ?? ''));
        $this->info('chat_preview='.$preview);

        return self::SUCCESS;
    }
}
