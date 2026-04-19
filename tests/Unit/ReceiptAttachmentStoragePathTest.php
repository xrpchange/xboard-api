<?php

namespace Tests\Unit;

use App\Services\ReceiptAttachmentStorage;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
#[Group('spec-ARB-010')]
class ReceiptAttachmentStoragePathTest extends TestCase
{
    public function test_absolute_path_if_exists_returns_path_under_local_disk(): void
    {
        Storage::fake('local');
        $relative = ReceiptAttachmentStorage::ATTACHMENTS_SUBDIR.'/2026-04-18/aabbccddeeff00112233445566778899.pdf';
        Storage::disk('local')->put($relative, '%PDF-1.4 test');

        $abs = ReceiptAttachmentStorage::absolutePathIfExists($relative);
        $this->assertNotNull($abs);
        $this->assertSame('%PDF-1.4 test', file_get_contents($abs));
    }

    public function test_absolute_path_if_exists_returns_null_when_missing(): void
    {
        Storage::fake('local');

        $this->assertNull(ReceiptAttachmentStorage::absolutePathIfExists(
            ReceiptAttachmentStorage::ATTACHMENTS_SUBDIR.'/2099-01-01/00000000000000000000000000000000.pdf'
        ));
    }
}
