<?php

namespace Tests\Unit;

use App\Services\ReceiptAttachmentStorage;
use Illuminate\Support\Facades\Config;
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

    public function test_move_tmp_to_permanent_uses_storage_when_attachments_root_set_but_file_only_on_local_disk(): void
    {
        Storage::fake('local');
        Config::set('payment_verification.attachments_root', sys_get_temp_dir().'/receipt_attach_root_missing_'.uniqid('', true));

        $date = '2026-05-03';
        $token = str_repeat('b', 32);
        $tmpRelative = ReceiptAttachmentStorage::ATTACHMENTS_SUBDIR.'/'.$date.'/tmp/'.$token.'.pdf';
        Storage::disk('local')->put($tmpRelative, '%PDF-1.4 test');

        $link = 'https://api.example.test/api/v1/payments/email-attachments/'.$date.'/tmp/'.$token.'.pdf';
        $newLink = ReceiptAttachmentStorage::moveTmpToPermanentAndReturnNewLink($link);

        $this->assertStringNotContainsString('/tmp/', $newLink);
        $permRelative = ReceiptAttachmentStorage::ATTACHMENTS_SUBDIR.'/'.$date.'/'.$token.'.pdf';
        $this->assertTrue(Storage::disk('local')->exists($permRelative));
        $this->assertFalse(Storage::disk('local')->exists($tmpRelative));
    }
}
