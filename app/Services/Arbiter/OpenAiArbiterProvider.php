<?php

namespace App\Services\Arbiter;

use App\Services\Arbiter\Contracts\ArbiterProvider;
use Illuminate\Support\Facades\Http;

class OpenAiArbiterProvider implements ArbiterProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds
    ) {}

    public function providerId(): string
    {
        return 'openai';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function generate(array $payload): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('OpenAI API key is not configured.');
        }

        $response = Http::timeout($this->timeoutSeconds)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'content-type' => 'application/json',
            ])
            ->post('https://api.openai.com/v1/chat/completions', [
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
            throw new \RuntimeException('OpenAI request failed with status ' . $response->status());
        }

        $json = $response->json();
        $text = (string) ($json['choices'][0]['message']['content'] ?? '');
        if (trim($text) === '') {
            throw new \RuntimeException('OpenAI returned empty response.');
        }

        return [
            'raw_text' => $text,
            'provider' => $this->providerId(),
            'model' => $this->model,
        ];
    }
}
