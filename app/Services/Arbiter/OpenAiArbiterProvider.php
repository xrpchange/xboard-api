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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function generate(array $payload): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('OpenAI API key is not configured.');
        }

        $pdfB64 = $payload['receipt_pdf_base64'] ?? null;
        if (is_string($pdfB64) && $pdfB64 !== '') {
            return $this->generateWithReceiptPdf($payload, $pdfB64);
        }

        $response = Http::timeout($this->timeoutSeconds)
            ->withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
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

        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI request failed with status '.$response->status());
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

    /**
     * PDF as a separate input_file (Responses API). Requires a vision-capable model (e.g. gpt-4o, gpt-4o-mini).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function generateWithReceiptPdf(array $payload, string $pdfBase64): array
    {
        $timeout = max($this->timeoutSeconds, 60);
        $response = Http::timeout($timeout)
            ->withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'content-type' => 'application/json',
            ])
            ->post('https://api.openai.com/v1/responses', [
                'model' => $this->model,
                'instructions' => (string) ($payload['system_prompt'] ?? ''),
                'input' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'input_file',
                                'filename' => 'receipt.pdf',
                                'file_data' => 'data:application/pdf;base64,'.$pdfBase64,
                            ],
                            [
                                'type' => 'input_text',
                                'text' => (string) ($payload['user_prompt'] ?? ''),
                            ],
                        ],
                    ],
                ],
                'max_output_tokens' => 500,
                'temperature' => 0,
            ]);

        if (! $response->successful()) {
            $snippet = mb_substr((string) $response->body(), 0, 500);

            throw new \RuntimeException('OpenAI Responses request failed with status '.$response->status().': '.$snippet);
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new \RuntimeException('OpenAI Responses returned invalid JSON.');
        }

        $text = $this->extractTextFromOpenAiResponses($json);
        if (trim($text) === '') {
            throw new \RuntimeException('OpenAI Responses returned empty output text.');
        }

        return [
            'raw_text' => $text,
            'provider' => $this->providerId(),
            'model' => $this->model,
        ];
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function extractTextFromOpenAiResponses(array $json): string
    {
        $out = $json['output'] ?? null;
        if (! is_array($out)) {
            return '';
        }
        foreach ($out as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (($item['type'] ?? '') !== 'message' || ($item['role'] ?? '') !== 'assistant') {
                continue;
            }
            $content = $item['content'] ?? null;
            if (! is_array($content)) {
                continue;
            }
            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'output_text' && isset($part['text'])) {
                    return (string) $part['text'];
                }
            }
        }

        return '';
    }
}
