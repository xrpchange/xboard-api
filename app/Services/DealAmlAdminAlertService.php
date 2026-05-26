<?php

namespace App\Services;

use App\Mail\DealAmlProviderFailureMail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Notifies the exchange operator when AML screening fails for infrastructure/provider reasons.
 */
class DealAmlAdminAlertService
{
    /**
     * @param  array<string, mixed>|null  $responseBody
     */
    public function notifyProviderFailure(
        int $exchangeLotId,
        int $transactionId,
        string $payoutContext,
        string $screeningSide,
        string $coreUrl,
        string $coreKey,
        int $coreHttpStatus,
        ?array $responseBody,
    ): void {
        $to = $this->resolveAdminEmail($coreUrl, $coreKey);
        if ($to === null || $to === '') {
            Log::warning('[deal-aml] provider failure alert skipped: admin email not configured', [
                'lot_id' => $exchangeLotId,
                'transaction_id' => $transactionId,
            ]);

            return;
        }

        $errorCode = is_array($responseBody)
            ? (string) ($responseBody['error_code'] ?? 'AML_PROVIDER_UNAVAILABLE')
            : 'AML_PROVIDER_UNAVAILABLE';

        $detailJson = json_encode(
            $responseBody ?? [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (! is_string($detailJson)) {
            $detailJson = '{}';
        }

        try {
            Mail::send(new DealAmlProviderFailureMail(
                toEmail: $to,
                exchangeLotId: $exchangeLotId,
                transactionId: $transactionId,
                payoutContext: $payoutContext,
                screeningSide: $screeningSide,
                coreHttpStatus: $coreHttpStatus,
                errorCode: $errorCode,
                detailJson: $detailJson,
            ));
            Log::info('[deal-aml] provider failure alert sent', [
                'lot_id' => $exchangeLotId,
                'transaction_id' => $transactionId,
                'to_preview' => substr($to, 0, 3).'***',
            ]);
        } catch (\Throwable $e) {
            Log::error('[deal-aml] provider failure alert send failed', [
                'lot_id' => $exchangeLotId,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveAdminEmail(string $coreUrl, string $coreKey): ?string
    {
        $override = strtolower(trim((string) config('services.aml.admin_alert_email', '')));
        if ($override !== '') {
            return $override;
        }

        $coreUrl = rtrim(trim($coreUrl), '/');
        if ($coreUrl !== '' && $coreKey !== '') {
            try {
                $resp = Http::timeout(8)
                    ->withHeaders(['X-Core-Key' => $coreKey])
                    ->post($coreUrl.'/api/internal/notification-admin-email', []);
                if ($resp->successful()) {
                    $body = $resp->json();
                    $email = is_array($body) ? strtolower(trim((string) ($body['admin_email'] ?? ''))) : '';
                    if ($email !== '') {
                        return $email;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[deal-aml] could not fetch admin email from Core', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $fallback = strtolower(trim((string) config('arbiter_operator_mail.escalation_email', '')));

        return $fallback !== '' ? $fallback : null;
    }
}
