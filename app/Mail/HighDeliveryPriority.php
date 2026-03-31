<?php

namespace App\Mail;

use Illuminate\Mail\Message;
use Symfony\Component\Mime\Email;

/**
 * Sets MIME priority 1 (highest) on all transactional mail.
 * No bulk/marketing use case — uniform high priority for delivery.
 */
final class HighDeliveryPriority
{
    /**
     * Apply high delivery priority for both legacy Illuminate\Mail\Message
     * and the newer Symfony\Component\Mime\Email transport.
     */
    public static function apply($message): void
    {
        if ($message instanceof Message) {
            $message->priority(1);
            return;
        }

        if ($message instanceof Email) {
            // Symfony Mailer: set standard priority headers
            $headers = $message->getHeaders();
            if (!$headers->has('X-Priority')) {
                $headers->addTextHeader('X-Priority', '1');
            }
            if (!$headers->has('Importance')) {
                $headers->addTextHeader('Importance', 'high');
            }
        }
    }

    /**
     * Inject priority headers into a raw RFC822 message when sending via HTTP APIs (Postmark/Resend/Mailgun raw).
     */
    public static function injectIntoRawMime(string $rawEmail): string
    {
        if (preg_match('/^X-Priority\s*:/mi', $rawEmail) || preg_match('/^Importance\s*:/mi', $rawEmail)) {
            return $rawEmail;
        }
        $crlfSep = "\r\n\r\n";
        $pos = strpos($rawEmail, $crlfSep);
        $sepLen = 4;
        if ($pos === false) {
            $crlfSep = "\n\n";
            $pos = strpos($rawEmail, $crlfSep);
            $sepLen = 2;
        }
        if ($pos === false) {
            return "X-Priority: 1\r\nImportance: high\r\n\r\n".$rawEmail;
        }

        return substr($rawEmail, 0, $pos)."\r\nX-Priority: 1\r\nImportance: high".$crlfSep.substr($rawEmail, $pos + $sepLen);
    }
}
