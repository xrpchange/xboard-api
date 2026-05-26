<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Internal alert when deal AML screening fails due to provider/server (not user wallet risk).
 * English only — operator-facing.
 */
class DealAmlProviderFailureMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $toEmail,
        public int $exchangeLotId,
        public int $transactionId,
        public string $payoutContext,
        public string $screeningSide,
        public int $coreHttpStatus,
        public string $errorCode,
        public string $detailJson,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'XBoard critical: AML screening provider failure (lot '.$this->exchangeLotId.')',
        );
    }

    public function content(): Content
    {
        $body = "AML screening provider/server failure during deal crypto payout.\n\n"
            ."Exchange lot ID: {$this->exchangeLotId}\n"
            ."Transaction ID: {$this->transactionId}\n"
            ."Payout context: {$this->payoutContext}\n"
            ."Screening side: {$this->screeningSide}\n"
            ."Core HTTP status: {$this->coreHttpStatus}\n"
            ."Error code: {$this->errorCode}\n\n"
            ."No AML screening charge was applied to the participant token.\n\n"
            ."Full payload (JSON):\n"
            .$this->detailJson;

        return new Content(
            textString: $body,
        );
    }
}
