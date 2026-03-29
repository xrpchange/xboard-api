<?php

namespace App\Services\Arbiter;

use App\Services\Arbiter\Contracts\ArbiterProvider;
use Illuminate\Support\Facades\Http;

class AnthropicArbiterProvider implements ArbiterProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds
    ) {}

    public function providerId(): string
    {
        return 'anthropic';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function generate(array $payload): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('Anthropic API key is not configured.');
        }

        $response = Http::timeout($this->timeoutSeconds)
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 350,
                'temperature' => 0,
                'system' => (string) ($payload['system_prompt'] ?? ''),
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => (string) ($payload['user_prompt'] ?? ''),
                    ],
                ],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Anthropic request failed with status ' . $response->status());
        }

        $json = $response->json();
        $text = '';
        $content = is_array($json['content'] ?? null) ? $json['content'] : [];
        foreach ($content as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'text') {
                $text .= (string) ($item['text'] ?? '');
            }
        }

        if (trim($text) === '') {
            throw new \RuntimeException('Anthropic returned empty response.');
        }

        return [
            'raw_text' => $text,
            'provider' => $this->providerId(),
            'model' => $this->model,
        ];
    }
}
