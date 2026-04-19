<?php

namespace Tests\Unit;

use App\Support\ReceiptPdfTextExtractor;
use App\Support\SandboxTestReceiptPdf;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
#[Group('spec-ARB-010')]
class ReceiptPdfTextExtractorTest extends TestCase
{
    public function test_extracts_visible_text_from_synthetic_pdf(): void
    {
        $pdf = SandboxTestReceiptPdf::build(['REF-ARB-TEST-999', 'Amount 100.00']);
        $excerpt = ReceiptPdfTextExtractor::tryExcerptFromPdfBytes($pdf, 5000);
        $this->assertStringContainsString('REF-ARB-TEST-999', $excerpt);
        $this->assertStringContainsString('100.00', $excerpt);
    }
}
