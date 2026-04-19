<?php

namespace App\Services\Arbiter;

use App\Mail\ArbiterOperatorEscalationMail;
use App\Support\ArbiterDealContextPlainText;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends internal English email to a human operator when automated receipt handling cannot proceed (e.g. scanned image-only PDF).
 */
final class ArbiterOperatorEscalationNotifier
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $rules
     */
    public static function notifyImageOnlyReceipt(array $context, array $rules): void
    {
        $to = trim((string) config('arbiter_operator_mail.escalation_email', ''));
        if ($to === '') {
            Log::warning('[arbiter] operator_escalation_email not configured; skipping operator email', [
                'lot_id' => $context['lot_id'] ?? null,
            ]);

            return;
        }

        $ctx = $context;
        $ctx['receipt_pdf_llm'] = $ctx['receipt_pdf_llm'] ?? 'receipt_pdf_image_only_operator_escalation';
        $ctx['receipt_pdf_text_excerpt'] = (string) ($ctx['receipt_pdf_text_excerpt'] ?? '');

        $dealContextPlain = ArbiterDealContextPlainText::build($ctx, $rules);
        $lotId = (string) ($ctx['lot_id'] ?? '?');
        Mail::send(new ArbiterOperatorEscalationMail($to, $lotId, $dealContextPlain));

        Log::info('[arbiter] operator_escalation_email_sent', [
            'lot_id' => $ctx['lot_id'] ?? null,
        ]);
    }
}
