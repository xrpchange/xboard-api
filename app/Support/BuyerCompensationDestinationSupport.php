<?php

namespace App\Support;

use App\Models\DealChatMessage;
use App\Models\ExchangeLot;
use App\Models\Transaction;

/**
 * Buyer-declared external wallet for operator compensation from seller escrow reservation.
 */
final class BuyerCompensationDestinationSupport
{
    public const TAG = 'buyer_compensation_destination';

    /**
     * @return array{address: string, crypto: string, message_id: int, tx_id: int|null}|null
     */
    public static function resolveLatestFromChat(ExchangeLot $lot, ?int $transactionId = null): ?array
    {
        $lotIdStr = (string) $lot->id;
        $messages = DealChatMessage::query()
            ->where('exchange_lot_id', $lot->id)
            ->where('sender_side', 'buyer')
            ->whereNotNull('deal_tag')
            ->orderByDesc('id')
            ->get(['id', 'deal_tag']);

        foreach ($messages as $message) {
            $raw = $message->deal_tag;
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            $obj = json_decode($raw, true);
            if (! is_array($obj)) {
                continue;
            }
            if (trim((string) ($obj['t'] ?? '')) !== self::TAG) {
                continue;
            }
            if (trim((string) ($obj['lot_id'] ?? '')) !== $lotIdStr) {
                continue;
            }
            $tagTxId = isset($obj['tx_id']) ? (int) $obj['tx_id'] : null;
            if ($transactionId !== null && $tagTxId !== null && $tagTxId !== $transactionId) {
                continue;
            }
            $address = trim((string) ($obj['address'] ?? ''));
            $crypto = strtolower(trim((string) ($obj['crypto'] ?? '')));
            if ($address === '' || $crypto === '') {
                continue;
            }

            return [
                'address' => $address,
                'crypto' => $crypto,
                'message_id' => (int) $message->id,
                'tx_id' => $tagTxId,
            ];
        }

        return null;
    }

    public static function hasBuyerCompensationDestinationInChat(ExchangeLot $lot): bool
    {
        return self::resolveLatestFromChat($lot) !== null;
    }

    /**
     * @param  array<string, mixed>  $dealTagData
     * @return array{message: string, error_code: string}|null
     */
    public static function validateAndEnrichIncomingTag(ExchangeLot $lot, array &$dealTagData): ?array
    {
        $address = trim((string) ($dealTagData['address'] ?? ''));
        if ($address === '') {
            return [
                'message' => 'Compensation wallet address must not be empty.',
                'error_code' => 'buyer_compensation_destination_invalid',
            ];
        }

        try {
            $expectedCrypto = DealEscrowCryptoType::sellerCoreType($lot);
        } catch (\InvalidArgumentException $e) {
            return [
                'message' => $e->getMessage(),
                'error_code' => 'buyer_compensation_destination_invalid',
            ];
        }

        $tagCrypto = strtolower(trim((string) ($dealTagData['crypto'] ?? '')));
        if ($tagCrypto === '') {
            $dealTagData['crypto'] = $expectedCrypto;
            $tagCrypto = $expectedCrypto;
        }
        if ($tagCrypto !== $expectedCrypto) {
            return [
                'message' => 'Compensation wallet crypto ('.$tagCrypto.') does not match seller escrow asset ('.$expectedCrypto.').',
                'error_code' => 'buyer_compensation_destination_crypto_mismatch',
            ];
        }

        $addrError = DealPayoutDestinationAddressValidation::validate($address, $expectedCrypto);
        if ($addrError !== null) {
            return $addrError;
        }

        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
        if ($buyerEmail !== '') {
            $transaction = Transaction::query()
                ->where('exchange_lot_id', $lot->id)
                ->where('buyer_lot_email', $buyerEmail)
                ->where('tx_type', 'sale')
                ->where('status', 'pending')
                ->orderByDesc('id')
                ->first();
            if ($transaction !== null && ! array_key_exists('tx_id', $dealTagData)) {
                $dealTagData['tx_id'] = (int) $transaction->id;
            }
        }

        $dealTagData['address'] = $address;
        $dealTagData['crypto'] = $expectedCrypto;
        $dealTagData['set_by'] = 'buyer';

        return null;
    }
}
