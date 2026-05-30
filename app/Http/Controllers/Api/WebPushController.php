<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebPushSubscription;
use App\Support\WebPushVapid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

final class WebPushController extends Controller
{
    public function vapidPublicKey(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => config('web_push.enabled', true) && WebPushVapid::configured(),
                'public_key' => WebPushVapid::configured() ? WebPushVapid::publicKey() : null,
            ],
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        if (! config('web_push.enabled', true) || ! WebPushVapid::configured()) {
            return response()->json([
                'success' => false,
                'message' => 'Web Push is not configured on this server.',
            ], 503);
        }

        $validator = Validator::make($request->all(), [
            'client_id' => 'required|string|min:8|max:64',
            'subscription' => 'required|array',
            'subscription.endpoint' => 'required|string|max:512',
            'subscription.keys' => 'required|array',
            'subscription.keys.p256dh' => 'required|string|max:255',
            'subscription.keys.auth' => 'required|string|max:64',
            'subscription.contentEncoding' => 'nullable|string|max:16',
            'watches' => 'required|array|min:1|max:32',
            'watches.*.lot_id' => 'required',
            'watches.*.node_key' => 'required|string|max:128',
            'watches.*.role' => 'required|string|in:buyer,seller',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $clientId = trim((string) $data['client_id']);
        $sub = $data['subscription'];
        $endpoint = trim((string) $sub['endpoint']);
        $p256dh = trim((string) $sub['keys']['p256dh']);
        $auth = trim((string) $sub['keys']['auth']);
        $encoding = trim((string) ($sub['contentEncoding'] ?? $sub['encoding'] ?? ''));
        if ($encoding === '') {
            // Chromium / Edge Push subscriptions use aes128gcm; legacy default was aesgcm.
            $encoding = 'aes128gcm';
        }

        $tokenHash = null;
        $tokenData = $request->attributes->get('escrow_token_data');
        if (is_array($tokenData) && isset($tokenData['token_hash'])) {
            $tokenHash = substr(trim((string) $tokenData['token_hash']), 0, 64);
        }

        $watches = [];
        foreach ($data['watches'] as $watch) {
            $lotId = (int) $watch['lot_id'];
            if ($lotId <= 0) {
                continue;
            }
            $nodeKey = strtolower(trim((string) $watch['node_key']));
            $nodeKey = (string) preg_replace('/^api\./i', '', $nodeKey);
            $role = strtolower(trim((string) $watch['role']));
            if ($nodeKey === '' || ! in_array($role, ['buyer', 'seller'], true)) {
                continue;
            }
            $watches[] = [
                'lot_id' => $lotId,
                'node_key' => $nodeKey,
                'role' => $role,
            ];
        }

        if ($watches === []) {
            return response()->json([
                'success' => false,
                'message' => 'No valid watches.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($clientId, $endpoint, $p256dh, $auth, $encoding, $watches, $tokenHash): void {
                WebPushSubscription::query()
                    ->where('client_id', $clientId)
                    ->where('endpoint', '!=', $endpoint)
                    ->delete();

                foreach ($watches as $watch) {
                    WebPushSubscription::query()->updateOrCreate(
                        [
                            'endpoint' => $endpoint,
                            'exchange_lot_id' => $watch['lot_id'],
                            'recipient_role' => $watch['role'],
                        ],
                        [
                            'client_id' => $clientId,
                            'public_key' => $p256dh,
                            'auth_token' => $auth,
                            'content_encoding' => $encoding,
                            'node_key' => $watch['node_key'],
                            'token_hash' => $tokenHash,
                        ]
                    );
                }

                $lotIds = array_unique(array_column($watches, 'lot_id'));
                WebPushSubscription::query()
                    ->where('endpoint', $endpoint)
                    ->whereNotIn('exchange_lot_id', $lotIds)
                    ->delete();
            });
        } catch (\Throwable $e) {
            Log::warning('[web-push] subscribe failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save subscription.',
            ], 500);
        }

        Log::info('[web-push] subscribed', [
            'client_id' => substr($clientId, 0, 8).'…',
            'watches' => count($watches),
        ]);

        return response()->json(['success' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|string|min:8|max:64',
            'endpoint' => 'nullable|string|max:512',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $clientId = trim((string) $validator->validated()['client_id']);
        $endpoint = isset($validator->validated()['endpoint'])
            ? trim((string) $validator->validated()['endpoint'])
            : null;

        $q = WebPushSubscription::query()->where('client_id', $clientId);
        if ($endpoint !== null && $endpoint !== '') {
            $q->where('endpoint', $endpoint);
        }
        $deleted = $q->delete();

        return response()->json([
            'success' => true,
            'data' => ['deleted' => $deleted],
        ]);
    }
}
