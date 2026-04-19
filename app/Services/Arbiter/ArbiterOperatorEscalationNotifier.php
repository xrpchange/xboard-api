<?php

namespace App\Services\Arbiter;

use App\Mail\ArbiterOperatorEscalationMail;
use App\Support\ArbiterOperatorEscalationDigest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends internal operator email (English template) when the arbiter needs human follow-up.
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

        $digest = ArbiterOperatorEscalationDigest::build($ctx, $rules, 'image_only_receipt', '');
        $lotId = (string) ($ctx['lot_id'] ?? '?');
        Mail::send(new ArbiterOperatorEscalationMail($to, $lotId, $digest, 'image_only_receipt', ''));

        Log::info('[arbiter] operator_escalation_email_sent', [
            'lot_id' => $ctx['lot_id'] ?? null,
            'kind' => 'image_only_receipt',
        ]);
    }

    /**
     * Rules-first manual paths (no LLM, provider failure, or low-confidence escalation): same inbox as image-only path.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $rules
     */
    public static function notifyPolicyManualReview(string $reasonCode, array $context, array $rules): void
    {
        $to = trim((string) config('arbiter_operator_mail.escalation_email', ''));
        if ($to === '') {
            Log::warning('[arbiter] operator_escalation_email not_configured_policy_skip', [
                'lot_id' => $context['lot_id'] ?? null,
                'reason_code' => $reasonCode,
            ]);

            return;
        }

        $ctx = $context;
        $ctx['receipt_pdf_llm'] = $ctx['receipt_pdf_llm'] ?? 'not_applicable_policy_escalation';
        $ctx['receipt_pdf_text_excerpt'] = (string) ($ctx['receipt_pdf_text_excerpt'] ?? '');

        $digest = ArbiterOperatorEscalationDigest::build($ctx, $rules, 'policy_manual', $reasonCode);
        $lotId = (string) ($ctx['lot_id'] ?? '?');
        Mail::send(new ArbiterOperatorEscalationMail($to, $lotId, $digest, 'policy_manual', $reasonCode));

        Log::info('[arbiter] operator_escalation_email_sent', [
            'lot_id' => $ctx['lot_id'] ?? null,
            'kind' => 'policy_manual',
            'reason_code' => $reasonCode,
        ]);
    }
}
