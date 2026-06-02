<?php

namespace Tests\Unit;

use App\Support\ClientWebOrigin;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * @see docs/DEAL_EMAIL_SUBJECT_TAG.md (client_web_origin)
 */
class ClientWebOriginTest extends TestCase
{
    #[Group('spec')]
    #[Group('spec-LOT-006')]
    public function test_normalize_accepts_https_origin_without_path(): void
    {
        $this->assertSame(
            'https://dev.xdexboard.com',
            ClientWebOrigin::normalize('https://dev.xdexboard.com/')
        );
    }

    #[Group('spec')]
    #[Group('spec-LOT-006')]
    public function test_normalize_upgrades_http_to_https(): void
    {
        $this->assertSame(
            'https://xrpchange.site',
            ClientWebOrigin::normalize('http://xrpchange.site')
        );
    }

    #[Group('spec')]
    #[Group('spec-LOT-006')]
    public function test_normalize_rejects_non_url_and_paths(): void
    {
        $this->assertNull(ClientWebOrigin::normalize(null));
        $this->assertNull(ClientWebOrigin::normalize(''));
        $this->assertNull(ClientWebOrigin::normalize('not-a-url'));
        $this->assertNull(ClientWebOrigin::normalize('https://dev.xdexboard.com/open-deal?lot_id=1'));
        $this->assertNull(ClientWebOrigin::normalize('ftp://dev.xdexboard.com'));
    }

    #[Group('spec')]
    #[Group('spec-LOT-006')]
    public function test_derive_from_api_host_maps_environment_prefix(): void
    {
        $this->assertSame(
            'https://xdexboard.com',
            ClientWebOrigin::deriveFromApiHost('https://dev.api.xdexboard.com')
        );
        $this->assertSame(
            'https://xdexboard.com',
            ClientWebOrigin::deriveFromApiHost('https://sandbox.api.xdexboard.com')
        );
        $this->assertSame(
            'https://xdexboard.com',
            ClientWebOrigin::deriveFromApiHost('https://api.xdexboard.com')
        );
        $this->assertSame(
            'https://xrpchange.site',
            ClientWebOrigin::deriveFromApiHost('https://dev.api.xrpchange.site')
        );
        $this->assertSame(
            'https://xrpchange.site',
            ClientWebOrigin::deriveFromApiHost('https://api.xrpchange.site')
        );
        $this->assertSame(
            'https://xrpchange.site',
            ClientWebOrigin::deriveFromApiHost('https://sandbox.api.xrpchange.site')
        );
    }

    #[Group('spec')]
    #[Group('spec-LOT-006')]
    public function test_resolve_sandbox_api_falls_back_to_production_web(): void
    {
        $this->assertSame(
            'https://xdexboard.com',
            ClientWebOrigin::resolve(null, 'https://sandbox.api.xdexboard.com')
        );
    }

    #[Group('spec')]
    #[Group('spec-LOT-006')]
    public function test_resolve_uses_origin_header_before_api_host_derivation(): void
    {
        $this->assertSame(
            'https://dev.xdexboard.com',
            ClientWebOrigin::resolve(null, 'https://api.xdexboard.com', 'https://dev.xdexboard.com')
        );
    }

    #[Group('spec')]
    #[Group('spec-LOT-006')]
    public function test_resolve_prefers_client_origin_over_api_derivation(): void
    {
        $this->assertSame(
            'https://xrpchange.site',
            ClientWebOrigin::resolve('https://xrpchange.site', 'https://dev.api.xrpchange.site')
        );
    }

    #[Group('spec')]
    #[Group('spec-LOT-011')]
    public function test_resolve_prefers_stored_lot_origin_over_node_api_host(): void
    {
        $this->assertSame(
            'https://xdexboard.com',
            ClientWebOrigin::resolve('xdexboard.com', 'https://api.xrpchange.site')
        );
    }

    #[Group('spec')]
    #[Group('spec-LOT-011')]
    public function test_host_for_storage_strips_scheme(): void
    {
        $this->assertSame('xdexboard.com', ClientWebOrigin::hostForStorage('https://xdexboard.com/'));
        $this->assertSame('xdexboard.com', ClientWebOrigin::hostForStorage('xdexboard.com'));
    }

    #[Group('spec')]
    #[Group('spec-LOT-011')]
    public function test_https_origin_from_storage_accepts_host_or_legacy_origin(): void
    {
        $this->assertSame('https://xdexboard.com', ClientWebOrigin::httpsOriginFromStorage('xdexboard.com'));
        $this->assertSame('https://xdexboard.com', ClientWebOrigin::httpsOriginFromStorage('https://xdexboard.com'));
    }
}
