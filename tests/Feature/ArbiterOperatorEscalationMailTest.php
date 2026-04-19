<?php

namespace Tests\Feature;

use App\Mail\ArbiterOperatorEscalationMail;
use App\Services\Arbiter\ArbiterOperatorEscalationNotifier;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
#[Group('spec-ARB-012')]
class ArbiterOperatorEscalationMailTest extends TestCase
{
    public function test_operator_escalation_sends_mailable_when_email_configured(): void
    {
        Mail::fake();
        config(['arbiter_operator_mail.escalation_email' => 'operator@example.test']);

        ArbiterOperatorEscalationNotifier::notifyImageOnlyReceipt([
            'lot_id' => 7,
            'requester_side' => 'buyer',
            'message' => str_repeat('a', 50),
            'pdf_sha256' => str_repeat('b', 64),
            'exchange_rules_digest' => 'rules digest line',
            'deal_machine_state' => 'wait_buyer_pay',
            'recent_chat_digest' => 'id=1 side=buyer tag=(none) body=hello',
        ], ['confidence' => 0.66, 'has_replay' => false]);

        Mail::assertSent(ArbiterOperatorEscalationMail::class, function (ArbiterOperatorEscalationMail $mail): bool {
            $html = $mail->render();

            return $mail->mailEscalationKind === 'image_only_receipt'
                && $mail->lotId === '7'
                && $mail->operatorEmail === 'operator@example.test'
                && str_contains($html, 'wait_buyer_pay')
                && str_contains($html, 'Recent conversation')
                && str_contains($html, 'Buyer')
                && str_contains($html, 'hello')
                && str_contains($html, 'Key facts')
                && str_contains($html, 'Reply in thread')
                && str_contains($html, 'Compensate buyer')
                && str_contains($html, 'Deny buyer')
                && str_contains($html, '/arbiter/operator/7/reply')
                && str_contains($html, 'signature=');
        });
    }

    public function test_operator_policy_escalation_sends_mail_when_email_configured(): void
    {
        Mail::fake();
        config(['arbiter_operator_mail.escalation_email' => 'operator@example.test']);

        ArbiterOperatorEscalationNotifier::notifyPolicyManualReview('insufficient_context', [
            'lot_id' => 3,
            'requester_side' => 'seller',
            'message' => 'short',
            'exchange_rules_digest' => 'digest',
            'deal_machine_state' => 'started',
            'recent_chat_digest' => '(none)',
        ], ['confidence' => 0.2, 'has_replay' => false]);

        Mail::assertSent(ArbiterOperatorEscalationMail::class, function (ArbiterOperatorEscalationMail $mail): bool {
            $sub = $mail->envelope()->subject;

            $html = $mail->render();

            return $mail->mailEscalationKind === 'policy_manual'
                && $mail->policyReasonCode === 'insufficient_context'
                && str_contains($sub, '[XBoard] Dispute on lot 3')
                && str_contains($sub, 'not enough information')
                && str_contains($html, 'Internal reference')
                && str_contains($html, 'insufficient_context');
        });
    }

    public function test_operator_action_signed_route_accepts_valid_signature(): void
    {
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'arbiter.operator.action',
            now()->addHour(),
            ['lot' => 99, 'action' => 'reply'],
        );

        $this->get($url)->assertOk()->assertSee('99', false)->assertSee('reply', false);
    }

    public function test_operator_action_route_rejects_unsigned_request(): void
    {
        $this->get('/arbiter/operator/99/reply')->assertForbidden();
    }
}
