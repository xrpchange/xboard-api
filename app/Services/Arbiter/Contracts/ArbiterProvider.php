<?php

namespace App\Services\Arbiter\Contracts;

interface ArbiterProvider
{
    public function providerId(): string;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function generate(array $payload): array;
}
