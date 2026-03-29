<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * @see docs/SPEC_REGISTRY.md API-001
 */
#[Group('spec')]
#[Group('spec-API-001')]
class ApiV1HealthTest extends TestCase
{
    public function test_spec_api_001_v1_health_returns_success_payload(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertTrue($data['success'] ?? false);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('status', $data['data']);
        $this->assertContains($data['data']['status'], ['ok', 'degraded']);
    }
}
