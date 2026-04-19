<?php

namespace App\Support;

use App\Models\ExchangeLot;
use App\Services\ReceiptAttachmentStorage;

/**
 * Locates a receipt PDF on disk for an exchange lot whose SHA-256 matches the client-provided fingerprint.
 */
final class ArbiterReceiptPdfResolver
{
    /**
     * @return non-empty-string|null Absolute path to a readable PDF whose sha256 matches $pdfSha256LowerHex (64 hex, lowercase).
     */
    public static function resolvePathMatchingSha256(ExchangeLot $lot, string $pdfSha256LowerHex): ?string
    {
        $want = strtolower(trim($pdfSha256LowerHex));
        if ($want === '' || ! preg_match('/^[a-f0-9]{64}$/', $want)) {
            return null;
        }

        $tx = $lot->latestReceiptTransaction();
        if ($tx === null) {
            return null;
        }

        $links = $tx->receipt_links_json;
        if (! is_array($links)) {
            return null;
        }

        foreach ($links as $item) {
            if (! is_array($item)) {
                continue;
            }
            $link = $item['link'] ?? null;
            if (! is_string($link) || $link === '') {
                continue;
            }
            $relative = ReceiptAttachmentStorage::linkToRelativePath($link);
            if ($relative === null) {
                continue;
            }
            $absolute = ReceiptAttachmentStorage::absolutePathIfExists($relative);
            if ($absolute === null) {
                continue;
            }
            $hash = hash_file('sha256', $absolute);
            if (! is_string($hash) || $hash === '') {
                continue;
            }
            if (hash_equals($want, strtolower($hash))) {
                return $absolute;
            }
        }

        return null;
    }
}
