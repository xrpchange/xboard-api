<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

/**
 * Best-effort UTF-8 text from a PDF (bank receipts). Used so every arbiter provider can see receipt content in the text prompt.
 */
final class ReceiptPdfTextExtractor
{
    /**
     * @return string Trimmed single-line-ish excerpt, empty on failure.
     */
    public static function tryExcerptFromPdfBytes(string $pdfBinary, int $maxChars): string
    {
        if ($maxChars <= 0 || $pdfBinary === '') {
            return '';
        }

        try {
            $parser = new Parser;
            $document = $parser->parseContent($pdfBinary);
            $text = trim($document->getText());
            $text = preg_replace('/\s+/u', ' ', $text) ?? '';
            if ($text === '') {
                return '';
            }

            return mb_substr($text, 0, $maxChars);
        } catch (\Throwable $e) {
            Log::debug('[arbiter] receipt_pdf_text_extract_failed', ['error' => $e->getMessage()]);

            return '';
        }
    }
}
