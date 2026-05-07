<?php

namespace App\Web;

/**
 * Public escrow token fields exposed to HTTP layer (after cache/Core validation).
 * Wire shape stays array (legacy keys) via {@see self::toArray()}.
 */
final readonly class EscrowTokenData
{
    /** @var array<string, mixed> */
    private const KNOWN_KEYS = [
        'wallet_address' => true,
        'currency' => true,
        'node_domain' => true,
        'environment' => true,
        'token_hash' => true,
    ];

    /**
     * @param  array<string, mixed>  $extra  Merged fields from Core / future extensions (excluding known keys)
     */
    public function __construct(
        public string $walletAddress,
        public ?string $currency,
        public ?string $nodeDomain,
        public ?string $environment,
        public ?string $tokenHash,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data  Merged payload (cache defaults + Core overrides)
     */
    public static function fromArray(array $data): self
    {
        $extra = $data;
        foreach (array_keys(self::KNOWN_KEYS) as $key) {
            unset($extra[$key]);
        }

        return new self(
            walletAddress: (string) ($data['wallet_address'] ?? ''),
            currency: isset($data['currency']) ? (string) $data['currency'] : null,
            nodeDomain: isset($data['node_domain']) ? (string) $data['node_domain'] : null,
            environment: isset($data['environment']) ? (string) $data['environment'] : null,
            tokenHash: isset($data['token_hash']) ? (string) $data['token_hash'] : null,
            extra: $extra,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge($this->extra, [
            'wallet_address' => $this->walletAddress,
            'currency' => $this->currency,
            'node_domain' => $this->nodeDomain,
            'environment' => $this->environment,
            'token_hash' => $this->tokenHash,
        ]);
    }
}
