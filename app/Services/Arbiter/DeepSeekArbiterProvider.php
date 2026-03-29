<?php

namespace App\Services\Arbiter;

use App\Services\Arbiter\Contracts\ArbiterProvider;
use Illuminate\Support\Facades\Http;

/**
 * DeepSeek exposes an OpenAI-compatible Chat Completions API (usable without Western-provider VPN in many regions).
 */
class DeepSeekArbiterProvider implements ArbiterProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeoutSeconds
    ) {}

    public function providerId(): string
    {
        return 'deepseek';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function generate(array $payload): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('DeepSeek API key is not configured.');
        }

        $url = rtrim($this->baseUrl, '/') . '/v1/chat/completions';

        $response = Http::timeout($this->timeoutSeconds)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'content-type' => 'application/json',
            ])
            ->post($url, [
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

        if (!$response->successful()) {
            throw new \RuntimeException('DeepSeek request failed with status ' . $response->status());
        }

        $json = $response->json();
        $text = (string) ($json['choices'][0]['message']['content'] ?? '');
        if (trim($text) === '') {
            throw new \RuntimeException('DeepSeek returned empty response.');
        }

        return [
            'raw_text' => $text,
            'provider' => $this->providerId(),
            'model' => $this->model,
        ];
    }
}
