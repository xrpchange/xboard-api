<?php

namespace Tests\Unit;

use App\Support\PublicHttpsUrl;
use PHPUnit\Framework\TestCase;

class PublicHttpsUrlTest extends TestCase
{
    public function test_upgrades_http_api_host_to_https(): void
    {
        $this->assertSame(
            'https://api.example.test',
            PublicHttpsUrl::upgradeMailBaseToHttps('http://api.example.test')
        );
    }

    public function test_upgrades_any_http_host_including_localhost_style_names(): void
    {
        $this->assertSame(
            'https://localhost:8000',
            PublicHttpsUrl::upgradeMailBaseToHttps('http://localhost:8000')
        );
        $this->assertSame(
            'https://127.0.0.1',
            PublicHttpsUrl::upgradeMailBaseToHttps('http://127.0.0.1')
        );
        $this->assertSame(
            'https://127.0.0.1:9000',
            PublicHttpsUrl::upgradeMailBaseToHttps('http://127.0.0.1:9000')
        );
    }

    public function test_preserves_https(): void
    {
        $this->assertSame(
            'https://api.example.test',
            PublicHttpsUrl::upgradeMailBaseToHttps('https://api.example.test/')
        );
    }
}
