<?php

namespace App\Support;

/**
 * Tiny single-page PDF (English technical text only) without extra Composer deps.
 */
final class SandboxTestReceiptPdf
{
    /**
     * @param  list<string>  $lines  ASCII-only lines
     */
    public static function build(array $lines): string
    {
        $stream = "BT /F1 11 Tf 50 760 Td 12 TL ";
        foreach ($lines as $i => $line) {
            if ($i > 0) {
                $stream .= "T* ";
            }
            $stream .= '(' . self::escapePdfLine((string) $line) . ") Tj ";
        }
        $stream .= 'ET';
        $streamCompressed = gzcompress($stream);
        $len = strlen($streamCompressed);

        $objects = [];
        $objects[] = '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj';
        $objects[] = '2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj';
        $objects[] = '3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj';
        $objects[] = '4 0 obj<</Length ' . $len . "/Filter/FlateDecode>>stream\n" . $streamCompressed . "\nendstream endobj";
        $objects[] = '5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj';

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= $obj . "\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 0; $i < count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer<</Size " . (count($objects) + 1) . "/Root 1 0 R>>\n";
        $pdf .= "startxref\n{$xrefPos}\n%%EOF";

        return $pdf;
    }

    private static function escapePdfLine(string $s): string
    {
        $s = preg_replace('/[^\x20-\x7E]/', '', $s) ?? '';

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }
}
