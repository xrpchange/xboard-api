<?php

namespace Tests\Unit;

use App\Services\Arbiter\GigaChatArbiterProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
class GigaChatAccessTokenCacheTtlTest extends TestCase
{
    public function test_ttl_from_seconds_expires_at(): void
    {
        $expiresAt = time() + 600;
        $ttl = GigaChatArbiterProvider::accessTokenCacheTtlSeconds(['expires_at' => $expiresAt]);
        $this->assertGreaterThanOrEqual(120, $ttl);
        $this->assertLessThanOrEqual(600, $ttl);
    }

    public function test_ttl_from_milliseconds_expires_at(): void
    {
        $expiresAtMs = (time() + 600) * 1000;
        $ttl = GigaChatArbiterProvider::accessTokenCacheTtlSeconds(['expires_at' => $expiresAtMs]);
        $this->assertGreaterThanOrEqual(120, $ttl);
        $this->assertLessThanOrEqual(600, $ttl);
    }
}
