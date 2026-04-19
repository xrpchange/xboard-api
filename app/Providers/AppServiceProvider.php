<?php

namespace App\Providers;

use App\Services\Arbiter\AnthropicArbiterProvider;
use App\Services\Arbiter\ArbiterService;
use App\Services\Arbiter\DeepSeekArbiterProvider;
use App\Services\Arbiter\GigaChatArbiterProvider;
use App\Services\Arbiter\OpenAiArbiterProvider;
use App\Services\Arbiter\StubArbiterProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\LotMailboxService::class, function ($app) {
            $config = config('services.lot_mailbox', []);

            return new \App\Services\LotMailboxService(
                domain: $config['domain'] ?? 'xdexboard.com',
                maildirBase: $config['maildir_base'] ?? null
            );
        });

        $this->app->singleton(ArbiterService::class, function ($app) {
            $arbiterConfig = config('services.arbiter', []);
            $anthropicConfig = is_array($arbiterConfig['anthropic'] ?? null) ? $arbiterConfig['anthropic'] : [];
            $openaiConfig = is_array($arbiterConfig['openai'] ?? null) ? $arbiterConfig['openai'] : [];
            $deepseekConfig = is_array($arbiterConfig['deepseek'] ?? null) ? $arbiterConfig['deepseek'] : [];
            $gigachatConfig = is_array($arbiterConfig['gigachat'] ?? null) ? $arbiterConfig['gigachat'] : [];

            $providers = [
                new StubArbiterProvider,
                new GigaChatArbiterProvider(
                    oauthCredential: (string) ($gigachatConfig['oauth_credential'] ?? ''),
                    scope: (string) ($gigachatConfig['scope'] ?? 'GIGACHAT_API_PERS'),
                    oauthUrl: (string) ($gigachatConfig['oauth_url'] ?? 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth'),
                    apiBaseUrl: (string) ($gigachatConfig['api_base_url'] ?? 'https://gigachat.devices.sberbank.ru/api/v1'),
                    model: (string) ($gigachatConfig['model'] ?? 'GigaChat'),
                    timeoutSeconds: (int) ($gigachatConfig['timeout'] ?? 45),
                    verifySsl: (bool) ($gigachatConfig['verify_ssl'] ?? true),
                ),
                new DeepSeekArbiterProvider(
                    apiKey: (string) ($deepseekConfig['api_key'] ?? ''),
                    baseUrl: (string) ($deepseekConfig['base_url'] ?? 'https://api.deepseek.com'),
                    model: (string) ($deepseekConfig['model'] ?? 'deepseek-chat'),
                    timeoutSeconds: (int) ($deepseekConfig['timeout'] ?? 30),
                ),
                new AnthropicArbiterProvider(
                    apiKey: (string) ($anthropicConfig['api_key'] ?? ''),
                    model: (string) ($anthropicConfig['model'] ?? 'claude-3-7-sonnet-latest'),
                    timeoutSeconds: (int) ($anthropicConfig['timeout'] ?? 20),
                ),
                new OpenAiArbiterProvider(
                    apiKey: (string) ($openaiConfig['api_key'] ?? ''),
                    model: (string) ($openaiConfig['model'] ?? 'gpt-4o-mini'),
                    timeoutSeconds: (int) ($openaiConfig['timeout'] ?? 20),
                ),
            ];

            return new ArbiterService(
                providers: $providers,
                primaryProvider: (string) ($arbiterConfig['primary_provider'] ?? 'gigachat'),
                noAiBelow: (float) ($arbiterConfig['no_ai_below'] ?? 0.35),
                manualReviewThreshold: (float) ($arbiterConfig['manual_review_threshold'] ?? 0.55),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Create necessary cache directories on first startup
        $this->ensureCacheDirectoriesExist();

        // Validate required configuration
        $this->validateRequiredConfig();
    }

    /**
     * Validate required configuration parameters
     */
    private function validateRequiredConfig(): void
    {
        $verificationEmail = config('mail.verification_email');
        if (! $verificationEmail) {
            throw new \RuntimeException(
                'PAYMENT_VERIFICATION_EMAIL is not set in .env file. '.
                'This parameter is required. Please add PAYMENT_VERIFICATION_EMAIL=check@xdexboard.com to your .env file.'
            );
        }
    }

    private function ensureCacheDirectoriesExist(): void
    {
        $directories = [
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
        ];

        foreach ($directories as $directory) {
            if (! file_exists($directory)) {
                mkdir($directory, 0755, true);
            }
        }
    }
}
