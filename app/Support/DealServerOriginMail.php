<?php

namespace App\Support;

/**
 * Deal tags that must arrive from system mail (MAIL_FROM_ADDRESS) on the email channel.
 *
 * Chat DB rows for the same tag types may use participant mailbox in from_email (API insert after
 * sendPaymentDetails / sendMessageToSeller). Use {@see sanitizeDealTagForChatRow} for API reads.
 */
final class DealServerOriginMail
{
    /**
     * Tag types that must not be accepted from a participant mailbox on raw email / IMAP.
     * Legitimate delivery uses From = config('mail.from.address').
     */
    private const EMAIL_FROM_SYSTEM_ONLY = [
        'payment_details',
    ];

    /**
     * Notification tags emailed to the counterparty from system mail (also listed for IMAP checks).
     */
    private const EMAIL_NOTIFICATION_FROM_SYSTEM = [
        'start_deal',
        'buyer_exited',
    ];

    public static function systemMailFromAddress(): string
    {
        return strtolower(trim((string) config('mail.from.address', 'noreply@xdexboard.com')));
    }

    public static function normalizeEmail(?string $email): string
    {
        if ($email === null) {
            return '';
        }
        $raw = trim($email);
        if ($raw === '') {
            return '';
        }
        $angle = strpos($raw, '<');
        if ($angle !== false) {
            $end = strpos($raw, '>', $angle);
            if ($end !== false) {
                $raw = substr($raw, $angle + 1, $end - $angle - 1);
            }
        }

        return strtolower(trim($raw));
    }

    public static function emailMatchesSystemMail(?string $fromEmail): bool
    {
        $from = self::normalizeEmail($fromEmail);
        $system = self::systemMailFromAddress();

        return $from !== '' && $system !== '' && hash_equals($system, $from);
    }

    public static function decodeTagType(?string $dealTagJson): ?string
    {
        if (! is_string($dealTagJson) || trim($dealTagJson) === '') {
            return null;
        }
        $obj = json_decode($dealTagJson, true);
        if (! is_array($obj)) {
            return null;
        }
        $t = isset($obj['t']) ? trim((string) $obj['t']) : '';

        return $t !== '' ? $t : null;
    }

    public static function isEmailFromSystemOnlyTag(string $tagType): bool
    {
        return in_array($tagType, self::EMAIL_FROM_SYSTEM_ONLY, true)
            || in_array($tagType, self::EMAIL_NOTIFICATION_FROM_SYSTEM, true);
    }

    /**
     * Raw email / IMAP: reject server-only tags when From is not system mail.
     */
    public static function sanitizeDealTagForEmailOrigin(?string $dealTagJson, ?string $fromEmail): ?string
    {
        $t = self::decodeTagType($dealTagJson);
        if ($t === null) {
            return $dealTagJson;
        }
        if (! self::isEmailFromSystemOnlyTag($t)) {
            return $dealTagJson;
        }
        if (! self::emailMatchesSystemMail($fromEmail)) {
            return null;
        }

        return $dealTagJson;
    }

    /**
     * Chat API: reject tag types that conflict with trusted sender_side in deal_chat_messages.
     */
    public static function sanitizeDealTagForChatRow(?string $dealTagJson, ?string $senderSide): ?string
    {
        $t = self::decodeTagType($dealTagJson);
        if ($t === null) {
            return $dealTagJson;
        }
        $side = strtolower(trim((string) ($senderSide ?? '')));

        if ($t === 'payment_details' && $side === 'buyer') {
            return null;
        }
        if (in_array($t, ['start_deal', 'buyer_exited'], true) && $side === 'seller') {
            return null;
        }

        return $dealTagJson;
    }

    /**
     * Chat API export: reject tag types that conflict with trusted sender_side in deal_chat_messages.
     *
     * Legitimate server actions store participant mailbox in from_email (e.g. payment_details from seller
     * after sendPaymentDetails). Email-origin checks apply only on the IMAP/raw-email path.
     */
    public static function trustedDealTagForChatExport(
        ?string $dealTagJson,
        ?string $senderSide,
        ?string $fromEmail
    ): ?string {
        $afterSide = self::sanitizeDealTagForChatRow($dealTagJson, $senderSide);
        if ($afterSide === null) {
            return null;
        }

        $t = self::decodeTagType($afterSide);
        if ($t === null || ! self::isEmailFromSystemOnlyTag($t)) {
            return $afterSide;
        }

        $side = strtolower(trim((string) ($senderSide ?? '')));
        if ($t === 'payment_details' && $side === 'seller') {
            return $afterSide;
        }
        if (in_array($t, ['start_deal', 'buyer_exited'], true) && $side === 'buyer') {
            return $afterSide;
        }

        // Row claims a server-mail-only tag but sender_side does not match a trusted API insert.
        if (self::normalizeEmail($fromEmail) !== '' && ! self::emailMatchesSystemMail($fromEmail)) {
            return null;
        }

        return $afterSide;
    }
}
