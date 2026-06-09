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

    /**
     * Latest receipt SHA-256 on file for this lot (transaction row or chat receipt_confirmation), lowercase hex.
     *
     * @return non-empty-string|null
     */
    public static function resolveLatestKnownReceiptSha256Hex(ExchangeLot $lot): ?string
    {
        $tx = $lot->latestReceiptTransaction();
        if ($tx !== null) {
            $links = $tx->receipt_links_json;
            if (is_array($links)) {
                foreach ($links as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $sha = strtolower(trim((string) ($item['sha256'] ?? '')));
                    if ($sha !== '' && preg_match('/^[a-f0-9]{64}$/', $sha)) {
                        return $sha;
                    }
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
                    if (is_string($hash) && $hash !== '') {
                        return strtolower($hash);
                    }
                }
            }
        }

        return self::resolveReceiptSha256FromChatTags($lot);
    }

    /**
     * @return non-empty-string|null
     */
    private static function resolveReceiptSha256FromChatTags(ExchangeLot $lot): ?string
    {
        $rows = $lot->dealChatMessages()
            ->orderByDesc('id')
            ->limit(80)
            ->get(['deal_tag']);

        foreach ($rows as $row) {
            $raw = trim((string) ($row->deal_tag ?? ''));
            if ($raw === '' || ! str_starts_with($raw, '{')) {
                continue;
            }
            $tag = json_decode($raw, true);
            if (! is_array($tag) || ($tag['t'] ?? '') !== 'receipt_confirmation') {
                continue;
            }
            $links = $tag['receipt_links'] ?? null;
            if (! is_array($links)) {
                continue;
            }
            foreach ($links as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $sha = strtolower(trim((string) ($item['sha256'] ?? '')));
                if ($sha !== '' && preg_match('/^[a-f0-9]{64}$/', $sha)) {
                    return $sha;
                }
            }
        }

        return null;
    }
}
