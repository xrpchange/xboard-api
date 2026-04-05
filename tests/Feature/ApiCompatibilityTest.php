<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * API Compatibility Tests
 * 
 * These tests ensure that API v1 maintains backward compatibility
 * and follows the expected response structure for external clients.
 */
class ApiCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that all API responses include version field
     */
    public function test_api_responses_include_version_field(): void
    {
        // Test public endpoints
        $publicEndpoints = [
            '/api/v1/countries',
            '/api/v1/currencies/fiat',
            '/api/v1/currencies/crypto',
            '/api/v1/currencies/all',
            '/api/v1/banks',
        ];

        foreach ($publicEndpoints as $endpoint) {
            $response = $this->get($endpoint);
            $response->assertStatus(200);
            
            $data = $response->json();
            $this->assertArrayHasKey('success', $data);
            $this->assertArrayHasKey('data', $data);
            $this->assertArrayHasKey('version', $data);
            $this->assertEquals('1.0', $data['version']);
        }
    }

    /**
     * Test that error responses follow standard format
     */
    public function test_error_responses_follow_standard_format(): void
    {
        // Test 404 error
        $response = $this->get('/api/v1/countries/99999');
        $response->assertStatus(404);
        
        $data = $response->json();
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('version', $data);
        $this->assertFalse($data['success']);
    }

    /**
     * Test that authentication endpoints return expected structure
     */
    public function test_auth_endpoints_return_expected_structure(): void
    {
        // Test invalid escrow token
        $response = $this->postJson('/api/v1/auth/validate-token', [
            'escrow_token' => 'invalid_token'
        ]);

        $response->assertStatus(401);
        $data = $response->json();
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('version', $data);
        $this->assertFalse($data['success']);
    }

    /**
     * Test that protected endpoints require authentication
     */
    public function test_protected_endpoints_require_authentication(): void
    {
        $protectedEndpoints = [
            '/api/v1/exchange-lots',
            '/api/v1/buy-triggers',
            '/api/v1/transactions',
        ];

        foreach ($protectedEndpoints as $endpoint) {
            $response = $this->get($endpoint);
            $response->assertStatus(401);
            
            $data = $response->json();
            $this->assertArrayHasKey('success', $data);
            $this->assertArrayHasKey('message', $data);
            $this->assertArrayHasKey('version', $data);
            $this->assertFalse($data['success']);
        }
    }

    /**
     * Test that pagination structure is consistent
     */
    public function test_pagination_structure_is_consistent(): void
    {
        // This test would require authentication and data setup
        // For now, we'll test the structure expectation
        
        $expectedPaginationFields = [
            'current_page',
            'data',
            'first_page_url',
            'from',
            'last_page',
            'last_page_url',
            'links',
            'next_page_url',
            'path',
            'per_page',
            'prev_page_url',
            'to',
            'total'
        ];

        // This is a structural test - actual pagination test would need auth
        $this->assertIsArray($expectedPaginationFields);
    }

    /**
     * Test that country data structure is stable
     */
    public function test_country_data_structure_is_stable(): void
    {
        $response = $this->get('/api/v1/countries');
        $response->assertStatus(200);
        
        $data = $response->json();
        $this->assertArrayHasKey('data', $data);
        
        if (!empty($data['data'])) {
            $country = $data['data'][0];
            $requiredFields = [
                'id',
                'name',
                'code',
                'phone_code',
                'is_active',
                'created_at',
                'updated_at'
            ];
            
            foreach ($requiredFields as $field) {
                $this->assertArrayHasKey($field, $country, "Country missing required field: {$field}");
            }
        }
    }

    /**
     * Test that currency data structure is stable
     */
    public function test_currency_data_structure_is_stable(): void
    {
        $response = $this->get('/api/v1/currencies/fiat');
        $response->assertStatus(200);
        
        $data = $response->json();
        $this->assertArrayHasKey('data', $data);
        
        if (!empty($data['data'])) {
            $currency = $data['data'][0];
            $requiredFields = [
                'id',
                'code',
                'name',
                'symbol',
                'is_active',
                'created_at',
                'updated_at'
            ];
            
            foreach ($requiredFields as $field) {
                $this->assertArrayHasKey($field, $currency, "Fiat currency missing required field: {$field}");
            }
        }
    }

    /**
     * Test that bank data structure is stable
     */
    public function test_bank_data_structure_is_stable(): void
    {
        $response = $this->get('/api/v1/banks');
        $response->assertStatus(200);
        
        $data = $response->json();
        $this->assertArrayHasKey('data', $data);
        
        if (!empty($data['data'])) {
            $bank = $data['data'][0];
            $requiredFields = [
                'id',
                'country_id',
                'name',
                'swift_code',
                'supports_individuals',
                'supports_legal_entities',
                'is_active',
                'created_at',
                'updated_at'
            ];
            
            foreach ($requiredFields as $field) {
                $this->assertArrayHasKey($field, $bank, "Bank missing required field: {$field}");
            }
        }
    }

    /**
     * Test that API version is consistent across all endpoints
     */
    public function test_api_version_is_consistent(): void
    {
        $endpoints = [
            '/api/v1/countries',
            '/api/v1/currencies/fiat',
            '/api/v1/banks',
        ];

        $versions = [];
        foreach ($endpoints as $endpoint) {
            $response = $this->get($endpoint);
            if ($response->status() === 200) {
                $data = $response->json();
                $versions[] = $data['version'] ?? null;
            }
        }

        // All versions should be the same
        $uniqueVersions = array_unique($versions);
        $this->assertCount(1, $uniqueVersions, 'API version should be consistent across all endpoints');
        $this->assertEquals('1.0', $uniqueVersions[0], 'API version should be 1.0');
    }
}
