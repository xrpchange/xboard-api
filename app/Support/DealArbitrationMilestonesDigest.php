<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\DealChatMessage;
use App\Models\ExchangeLot;

/**
 * Structured deal milestones for arbiter LLM context — avoids misreading raw chat alone.
 */
final class DealArbitrationMilestonesDigest
{
    /** @var list<string> */
    private const MILESTONE_TAG_TYPES = [
        'start_deal',
        'payment_details',
        'receipt_confirmation',
        'buyer_crypto_tx',
        'seller_received_funds',
        'buyer_destination_details',
        'seller_transfer_tx',
        'deal_completed_seller',
        'deal_completed_buyer',
        'dispute_requested_by_buyer',
        'dispute_requested_by_seller',
        'arbiter_message',
        'operator_mail_reply',
        'operator_escalation_requested',
        'seller_stage_undo_request',
        'buyer_stage_undo_reject',
        'buyer_stage_undo_confirm',
    ];

    public static function forLot(ExchangeLot $lot): string
    {
        $rows = DealChatMessage::query()
            ->where('exchange_lot_id', $lot->id)
            ->orderBy('id')
            ->get(['id', 'sender_side', 'deal_tag']);

        $lines = [];
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
            if (! in_array($tagType, self::MILESTONE_TAG_TYPES, true)) {
                continue;
            }
            $lines[] = self::formatMilestoneLine((int) $row->id, (string) ($row->sender_side ?? '?'), $tagType, $tag);
        }

        if ($lines === []) {
            return '(no milestone deal_tag rows recorded for this lot)';
        }

        return implode("\n", $lines);
    }

    public static function stateMeaning(string $state): string
    {
        return match ($state) {
            DealChatTagSequenceValidator::STATE_IDLE => 'No deal started yet.',
            DealChatTagSequenceValidator::STATE_DEAL_STARTED => 'Deal opened; seller should send payment details.',
            DealChatTagSequenceValidator::STATE_WAIT_BUYER_PAY => 'Seller sent payment details; buyer should pay (bank receipt or crypto tx).',
            DealChatTagSequenceValidator::STATE_WAIT_SELLER_CONFIRM => 'Buyer submitted payment proof; seller should confirm funds received.',
            DealChatTagSequenceValidator::STATE_WAIT_BUYER_DEST => 'Seller confirmed fiat received; buyer should send crypto destination details.',
            DealChatTagSequenceValidator::STATE_WAIT_SELLER_TRANSFER => 'Buyer destination is on file; seller must send crypto payout (seller_transfer_tx or deal_completed_seller). Seller confirming fiat (seller_received_funds) already happened if state reached here.',
            DealChatTagSequenceValidator::STATE_WAIT_BUYER_COMPLETE => 'Seller posted crypto payout; buyer should confirm deal completion.',
            DealChatTagSequenceValidator::STATE_COMPLETED => 'Deal completed.',
            DealChatTagSequenceValidator::STATE_BUYER_EXITED => 'Buyer exited the deal.',
            DealChatTagSequenceValidator::STATE_SELLER_EXITED => 'Seller exited the deal.',
            default => 'Unknown stage code.',
        };
    }

    /**
     * @param  array<string, mixed>  $tag
     */
    private static function formatMilestoneLine(int $messageId, string $side, string $tagType, array $tag): string
    {
        $extras = [];
        if ($tagType === 'receipt_confirmation' && is_array($tag['receipt_links'] ?? null)) {
            $extras[] = 'receipt_pdf_count='.count($tag['receipt_links']);
            foreach ($tag['receipt_links'] as $link) {
                if (! is_array($link)) {
                    continue;
                }
                $sha = trim((string) ($link['sha256'] ?? ''));
                if ($sha !== '') {
                    $extras[] = 'receipt_sha256='.$sha;
                    break;
                }
            }
        }
        if ($tagType === 'buyer_crypto_tx') {
            $tx = trim((string) ($tag['tx_ref'] ?? $tag['transaction_id'] ?? ''));
            if ($tx !== '') {
                $extras[] = 'tx_ref='.$tx;
            }
        }
        if ($tagType === 'seller_transfer_tx') {
            $tx = trim((string) ($tag['tx_number'] ?? $tag['tx_ref'] ?? ''));
            if ($tx !== '') {
                $extras[] = 'tx='.$tx;
            }
        }
        if ($tagType === 'deal_completed_seller') {
            $tx = trim((string) ($tag['crypto_payout_tx'] ?? ''));
            if ($tx !== '') {
                $extras[] = 'crypto_payout_tx='.$tx;
            }
        }
        if ($tagType === 'buyer_destination_details') {
            $addr = trim((string) ($tag['address'] ?? ''));
            $crypto = trim((string) ($tag['crypto'] ?? ''));
            if ($addr !== '') {
                $extras[] = 'dest='.$crypto.':'.$addr;
            }
        }
        if ($tagType === 'payment_details') {
            $bank = trim((string) ($tag['bank_data'] ?? ''));
            if ($bank !== '') {
                $extras[] = 'bank_data='.mb_substr($bank, 0, 120);
            }
        }

        $suffix = $extras !== [] ? ' '.implode(' ', $extras) : '';

        return "id={$messageId} side={$side} milestone={$tagType}{$suffix}";
    }
}
