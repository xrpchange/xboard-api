<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\DealChatMessage;
use App\Models\ExchangeLot;

/**
 * Declared on-chain (and bank-receipt) transfer proofs from deal chat tags — for operator mail and arbiter prompts.
 */
final class DealArbitrationTxProofCatalog
{
    private const TX_TAG_TYPES = [
        'buyer_crypto_tx',
        'receipt_confirmation',
        'seller_transfer_tx',
        'deal_completed_seller',
    ];

    /**
     * @return list<array{
     *   message_id: int,
     *   sender_side: string,
     *   tag_type: string,
     *   label: string,
     *   tx_id: string,
     *   crypto_type: string|null,
     *   explorer_url: string|null,
     *   core_tx_check: array<string, mixed>|null,
     *   bank_receipt_only: bool,
     *   receipt_urls: list<array{label: string, url: string}>,
     *   verified_at_send: bool|null,
     *   chain_environment: string,
     * }>
     */
    public static function forLot(ExchangeLot $lot, ?string $environment = null): array
    {
        $environment = $environment ?? ApiDeploymentEnvironment::current();
        $rows = DealChatMessage::query()
            ->where('exchange_lot_id', $lot->id)
            ->orderBy('id')
            ->get(['id', 'sender_side', 'deal_tag']);

        $out = [];
        foreach ($rows as $row) {
            $raw = trim((string) ($row->deal_tag ?? ''));
            if ($raw === '' || ! str_starts_with($raw, '{')) {
                continue;
            }
            $tag = json_decode($raw, true);
            if (! is_array($tag)) {
                continue;
            }
            $tagType = trim((string) ($tag['t'] ?? ''));
            if (! in_array($tagType, self::TX_TAG_TYPES, true)) {
                continue;
            }

            $txId = self::extractTxId($tagType, $tag);
            $receiptLinks = $tag['receipt_links'] ?? null;
            $hasReceiptLinks = is_array($receiptLinks) && count($receiptLinks) > 0;
            $bankReceiptOnly = ($tagType === 'receipt_confirmation' && $hasReceiptLinks)
                || ($tagType === 'seller_transfer_tx' && $txId === '' && $hasReceiptLinks);

            if ($txId === '' && ! $bankReceiptOnly) {
                continue;
            }

            $cryptoType = null;
            $explorerUrl = null;
            if ($txId !== '') {
                try {
                    $cryptoType = self::cryptoTypeForTag($lot, $tagType);
                    $explorerUrl = CryptoTxExplorerUrl::forTx($cryptoType, $txId, $environment);
                } catch (\InvalidArgumentException) {
                    $cryptoType = null;
                }
            }

            $coreCheck = is_array($tag['core_tx_check'] ?? null) ? $tag['core_tx_check'] : null;
            $verifiedAtSend = is_array($coreCheck) && array_key_exists('verified', $coreCheck)
                ? (bool) ($coreCheck['verified'] ?? false)
                : null;

            $out[] = [
                'message_id' => (int) $row->id,
                'sender_side' => (string) ($row->sender_side ?? ''),
                'tag_type' => $tagType,
                'label' => self::labelForTag($tagType, $bankReceiptOnly),
                'tx_id' => $txId,
                'crypto_type' => $cryptoType,
                'explorer_url' => $explorerUrl,
                'core_tx_check' => $coreCheck,
                'bank_receipt_only' => $bankReceiptOnly,
                'receipt_urls' => $bankReceiptOnly ? self::parseReceiptUrls($tag) : [],
                'verified_at_send' => $verifiedAtSend,
                'chain_environment' => $environment,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $proofs
     */
    public static function formatDigestLines(array $proofs): string
    {
        if ($proofs === []) {
            return '(no declared crypto transfer ids or bank transfer receipts in chat tags)';
        }

        $lines = [];
        foreach ($proofs as $p) {
            $msgId = (int) ($p['message_id'] ?? 0);
            $label = (string) ($p['label'] ?? 'Transfer');
            $side = (string) ($p['sender_side'] ?? '?');
            $tagType = (string) ($p['tag_type'] ?? '');
            if (! empty($p['bank_receipt_only'])) {
                $bodyHint = $tagType === 'receipt_confirmation'
                    ? '(buyer bank receipt in deal_tag.receipt_links)'
                    : '(seller bank receipt in deal_tag.receipt_links)';
                $lines[] = "id={$msgId} side={$side} tag={$tagType} kind=bank_receipt_proof body={$bodyHint}";

                continue;
            }
            $tx = (string) ($p['tx_id'] ?? '');
            $crypto = (string) ($p['crypto_type'] ?? '');
            $check = is_array($p['core_tx_check'] ?? null) ? $p['core_tx_check'] : [];
            $verified = array_key_exists('verified', $check) ? (($check['verified'] ?? false) ? 'true' : 'false') : 'unknown';
            $checkedAt = (string) ($check['checked_at_utc'] ?? '');
            $suffix = $checkedAt !== '' ? " core_tx_check.verified={$verified} checked_at_utc={$checkedAt}" : " core_tx_check.verified={$verified}";
            $lines[] = "id={$msgId} side={$side} tag={$tagType} crypto={$crypto} tx_id={$tx}{$suffix} label={$label}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $tag
     */
    private static function extractTxId(string $tagType, array $tag): string
    {
        if ($tagType === 'deal_completed_seller') {
            return trim((string) ($tag['crypto_payout_tx'] ?? ''));
        }

        return trim((string) ($tag['tx_ref'] ?? $tag['transaction_id'] ?? $tag['tx_number'] ?? ''));
    }

    private static function cryptoTypeForTag(ExchangeLot $lot, string $tagType): string
    {
        return match ($tagType) {
            'buyer_crypto_tx' => DealEscrowCryptoType::buyerCoreType($lot),
            'seller_transfer_tx', 'deal_completed_seller' => DealEscrowCryptoType::sellerCoreType($lot),
            default => throw new \InvalidArgumentException('Unsupported tag type for crypto type resolution.'),
        };
    }

    /**
     * @param  array<string, mixed>  $tag
     * @return list<array{label: string, url: string}>
     */
    private static function parseReceiptUrls(array $tag): array
    {
        $links = $tag['receipt_links'] ?? null;
        if (! is_array($links)) {
            return [];
        }
        $out = [];
        foreach ($links as $item) {
            if (! is_array($item)) {
                continue;
            }
            $url = trim((string) ($item['link'] ?? ''));
            if ($url === '' || ! str_starts_with($url, 'https://')) {
                continue;
            }
            $name = trim((string) ($item['filename'] ?? ''));
            $out[] = [
                'label' => $name !== '' ? $name : 'Receipt PDF',
                'url' => $url,
            ];
        }

        return $out;
    }

    private static function labelForTag(string $tagType, bool $bankReceiptOnly): string
    {
        if ($bankReceiptOnly) {
            return $tagType === 'receipt_confirmation'
                ? 'Buyer bank payment (receipt proof)'
                : 'Seller bank transfer (receipt proof)';
        }

        return match ($tagType) {
            'buyer_crypto_tx' => 'Buyer crypto transfer',
            'seller_transfer_tx' => 'Seller outgoing transfer',
            'deal_completed_seller' => 'Seller crypto payout (deal completed)',
            default => 'Declared transfer',
        };
    }
}
