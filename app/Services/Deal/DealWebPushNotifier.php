<?php

declare(strict_types=1);

namespace App\Services\Deal;

use App\Models\ExchangeLot;
use App\Models\WebPushSubscription;
use App\Support\ClientWebOrigin;
use App\Support\OpenSslWindowsConfig;
use App\Support\WebPushVapid;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Sends Web Push when a deal chat message is persisted (complements IMAP IDLE in an active tab).
 */
final class DealWebPushNotifier
{
    /**
     * @return list<string>
     */
    private function recipientRolesForSender(string $senderSide): array
    {
        $side = strtolower(trim($senderSide));

        return match ($side) {
            'buyer' => ['seller'],
            'seller' => ['buyer'],
            default => ['buyer', 'seller'],
        };
    }

    public function notifyDealChatRecipient(ExchangeLot $lot, string $senderSide): void
    {
        if (! config('web_push.enabled', true) || ! WebPushVapid::configured()) {
            return;
        }

        $roles = $this->recipientRolesForSender($senderSide);
        if ($roles === []) {
            return;
        }

        $lot->loadMissing('node');
        $nodeKey = $this->normalizeNodeKey($lot->node?->domain);
        if ($nodeKey === null) {
            Log::debug('[deal-web-push] skip: no node_key', ['lot_id' => $lot->id]);

            return;
        }

        $rows = WebPushSubscription::query()
            ->where('exchange_lot_id', $lot->id)
            ->whereIn('recipient_role', $roles)
            ->get();

        if ($rows->isEmpty()) {
            $totalForLot = WebPushSubscription::query()->where('exchange_lot_id', $lot->id)->count();
            Log::info('[deal-web-push] skip: no matching subscriptions', [
                'lot_id' => $lot->id,
                'recipient_roles' => $roles,
                'sender_side' => $senderSide,
                'subscriptions_on_lot' => $totalForLot,
            ]);

            return;
        }

        Log::info('[deal-web-push] sending', [
            'lot_id' => $lot->id,
            'sender_side' => $senderSide,
            'recipient_roles' => $roles,
            'targets' => $rows->count(),
        ]);

        $openUrl = $this->openDealUrl($nodeKey, (string) $lot->id, $lot);
        $payload = json_encode([
            'title' => 'XBoard',
            'body' => 'Deal #'.$lot->id,
            'lot_id' => (string) $lot->id,
            'node_key' => $nodeKey,
            'url' => $openUrl,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($payload)) {
            return;
        }

        try {
            $opensslCnf = OpenSslWindowsConfig::applyIfNeeded();
            if (PHP_OS_FAMILY === 'Windows' && ! OpenSslWindowsConfig::ecKeyWorks()) {
                Log::warning('[deal-web-push] openssl ec unavailable', [
                    'lot_id' => $lot->id,
                    'openssl_conf' => $opensslCnf ?? getenv('OPENSSL_CONF') ?: null,
                    'apache_bin_cnf_exists' => is_file('C:/xampp/apache/bin/openssl.cnf'),
                ]);

                return;
            }
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => (string) config('web_push.vapid.subject'),
                    'publicKey' => WebPushVapid::publicKey(),
                    'privateKey' => (string) config('web_push.vapid.private_key'),
                ],
            ]);

            foreach ($rows as $row) {
                $sub = Subscription::create([
                    'endpoint' => $row->endpoint,
                    'keys' => [
                        'p256dh' => $row->public_key,
                        'auth' => $row->auth_token,
                    ],
                    'contentEncoding' => $row->content_encoding ?: 'aesgcm',
                ]);
                $webPush->queueNotification($sub, $payload);
            }

            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    Log::info('[deal-web-push] sent', ['lot_id' => $lot->id]);

                    continue;
                }
                $endpoint = $report->getEndpoint();
                $status = $report->getResponse()?->getStatusCode();
                Log::info('[deal-web-push] send failed', [
                    'lot_id' => $lot->id,
                    'endpoint' => $endpoint !== '' ? substr($endpoint, 0, 80).'…' : '',
                    'reason' => $report->getReason(),
                    'status' => $status,
                ]);
                if ($status === 404 || $status === 410) {
                    WebPushSubscription::query()->where('endpoint', $endpoint)->delete();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[deal-web-push] batch failed', [
                'lot_id' => $lot->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function openDealUrl(string $nodeKey, string $lotId, ExchangeLot $lot): string
    {
        $query = http_build_query([
            'lot_id' => $lotId,
            'node_key' => $nodeKey,
        ], '', '&', PHP_QUERY_RFC3986);
        $apiBase = app()->runningInConsole()
            ? null
            : rtrim((string) request()->getSchemeAndHttpHost(), '/');
        $stored = $lot->client_web_origin;
        $origin = ClientWebOrigin::resolve(
            is_string($stored) && trim($stored) !== '' ? $stored : null,
            $apiBase,
            app()->runningInConsole() ? null : request()->header('Origin')
        );
        $base = $origin ?? ('https://'.ClientWebOrigin::DEFAULT_PROJECT_DOMAIN);

        return rtrim($base, '/').'/open-deal?'.$query;
    }

    private function normalizeNodeKey(?string $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $nodeKey = strtolower(trim($raw));
        $nodeKey = (string) preg_replace('/^api\./i', '', $nodeKey);

        return $nodeKey !== '' ? $nodeKey : null;
    }
}
