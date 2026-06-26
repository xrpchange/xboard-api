<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use App\Support\BuyerExternalEmailReputation;
use App\Services\SandboxVirtualBankService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExchangeLotResource extends JsonResource
{
    public function toArray($request)
    {
        $buyAmounts = $this->buy_amounts ?? [];
        $sellAmount = (float) $this->sell_amount;
        // Use stored rate if present; else compute only when single buy_amount
        $rate = isset($this->rate) && (float) $this->rate > 0
            ? (float) $this->rate
            : ($sellAmount > 0 && count($buyAmounts) === 1 ? $buyAmounts[0] / $sellAmount : 0);

        $status = 'active';
        if (!$this->is_active) {
            $status = 'inactive';
        } elseif ($this->expires_at && $this->expires_at->isPast()) {
            $status = 'expired';
        }

        $out = [
            'id' => (string) $this->id,
            'node_id' => $this->node_id ? (string) $this->node_id : null,
            'node_domain' => $this->relationLoaded('node') ? ($this->node?->domain ?? null) : null,
            'from_currency' => $this->from_currency ?? '',
            'to_currency' => $this->to_currency ?? '',
            'from_currency_type' => self::currencyTypeForLotSide($this, 'from'),
            'to_currency_type' => self::currencyTypeForLotSide($this, 'to'),
            'sell_amount' => (float) $this->sell_amount,
            'sold_crypto_amount' => (float) ($this->sold_crypto_amount ?? 0),
            'completed_deals_count' => (int) ($this->completed_deals_count ?? 0),
            'bad_deals_count' => (int) ($this->bad_deals_count ?? 0),
            'buy_amount_min' => (float) $this->buy_amount_min,
            'buy_amount_max' => (float) $this->buy_amount_max,
            'buy_amounts' => $buyAmounts,
            'rate' => (float) $rate,
            'status' => $status,
            'crypto_currency_id' => $this->crypto_currency_id,
            'to_crypto_code' => $this->relationLoaded('cryptoCurrency') && $this->cryptoCurrency ? $this->cryptoCurrency->code : null,
            'to_crypto_display' => $this->relationLoaded('cryptoCurrency') && $this->cryptoCurrency ? self::formatCryptoCodeForDisplay($this->cryptoCurrency->code) : null,
            'wallet_address' => $this->wallet_address ?? '',
            'bank_id' => SandboxVirtualBankService::lotUsesVirtualSandboxBank($this->resource)
                ? SandboxVirtualBankService::virtualBankId()
                : $this->bank_id,
            'bank_name' => SandboxVirtualBankService::lotUsesVirtualSandboxBank($this->resource)
                ? config('sandbox_virtual_bank.name_en')
                : ($this->relationLoaded('bank') && $this->bank ? $this->bank->name : null),
            'virtual_sandbox_bank_code' => $this->virtual_sandbox_bank_code,
            'bank_ids' => $this->relationLoaded('banks')
                ? $this->banks->pluck('id')->values()->all()
                : [],
            'bank_names' => $this->relationLoaded('banks')
                ? $this->banks->pluck('name')->values()->all()
                : [],
            'note' => $this->note ?? '',
            'seller_locale' => $this->seller_locale ?? '',
            'buyer_lot_email' => $this->buyer_lot_email ? (string) $this->buyer_lot_email : null,
            'buyer_selected_amount' => isset($this->buyer_selected_amount) && (float) $this->buyer_selected_amount > 0 ? (float) $this->buyer_selected_amount : null,
            'seller_lot_email' => $this->seller_lot_email ? (string) $this->seller_lot_email : null,
            'payment_purpose' => $this->payment_purpose ?? '',
            'aml' => $this->aml !== null ? (bool) $this->aml : null,
            'buyer_payment_timeout_minutes' => \App\Support\BuyerPaymentTimeoutMinutes::effectiveForLot(
                isset($this->buyer_payment_timeout_minutes) ? (int) $this->buyer_payment_timeout_minutes : null
            ),
            'seller_notification_email' => $this->seller_notification_email ? (string) $this->seller_notification_email : null,
            'buyer_notification_email' => $this->buyer_notification_email ? (string) $this->buyer_notification_email : null,
            'escrow_multisig_2_of_3_required' => (bool) ($this->escrow_multisig_2_of_3_required ?? false),
            'is_training' => (bool) ($this->is_training ?? false),
            'created_at' => self::toUtcIso8601($this->created_at) ?? Carbon::now('UTC')->toIso8601String(),
            'updated_at' => self::toUtcIso8601($this->updated_at) ?? self::toUtcIso8601($this->created_at) ?? Carbon::now('UTC')->toIso8601String(),
            'expires_at' => self::toUtcIso8601($this->expires_at) ?? '',
        ];

        if (self::shouldExposeBuyerLastSeenIpForSeller($request, $this->resource)) {
            [$share, $ip] = self::buyerConnectionInfoForSeller($this->resource);
            $out['buyer_ip_share_with_seller'] = $share;
            $out['buyer_last_seen_ip'] = ($share && $ip !== null && $ip !== '') ? (string) $ip : null;

            $buyerReputation = BuyerExternalEmailReputation::countsForEmail(
                is_string($this->buyer_notification_email) ? $this->buyer_notification_email : null
            );
            if ($buyerReputation !== null) {
                $out['buyer_completed_deals_count'] = $buyerReputation['completed_deals_count'];
                $out['buyer_bad_deals_count'] = $buyerReputation['bad_deals_count'];
            }
        }

        return $out;
    }

    /**
     * After payment_details, IP lives on the latest sale row for this buyer; before that, on exchange_lots.
     *
     * @return array{0:bool,1:?string} [share, ip or null]
     */
    private static function buyerConnectionInfoForSeller($lot): array
    {
        $buyerEmail = trim((string) ($lot->buyer_lot_email ?? ''));
        if ($buyerEmail === '' || $lot->id === null) {
            return [false, null];
        }
        $saleTx = Transaction::query()
            ->where('exchange_lot_id', $lot->id)
            ->whereRaw('LOWER(TRIM(buyer_lot_email)) = ?', [strtolower($buyerEmail)])
            ->where('tx_type', 'sale')
            ->orderByDesc('id')
            ->first();
        if ($saleTx !== null) {
            $share = (bool) ($saleTx->buyer_ip_share_with_seller ?? false);
            $ip = $share ? ($saleTx->buyer_seen_ip ?? null) : null;

            return [$share, is_string($ip) ? $ip : null];
        }
        $share = (bool) ($lot->buyer_ip_share_with_seller ?? false);
        $ip = $share ? ($lot->buyer_seen_ip ?? null) : null;

        return [$share, is_string($ip) ? $ip : null];
    }

    /**
     * Seller-only: exposes whether the buyer opted in to share connection IP and the address when true
     * (from exchange_lots before payment_details, then from the sale transaction).
     * Never for public or buyer tokens.
     */
    private static function shouldExposeBuyerLastSeenIpForSeller(?Request $request, $lot): bool
    {
        if ($request === null) {
            return false;
        }
        if (self::requestIsSellerPlacedLotsQuery($request)) {
            return true;
        }
        $token = $request->header('X-Escrow-Token') ?? $request->input('escrow_token');
        if (! is_string($token) || $token === '') {
            return false;
        }
        $decoded = json_decode($token, true);
        if (! is_array($decoded)) {
            return false;
        }
        $hash = isset($decoded['token_hash']) ? strtolower(trim((string) $decoded['token_hash'])) : '';
        if ($hash === '') {
            return false;
        }
        $lotWalletHash = strtolower(trim((string) ($lot->wallet_hash ?? '')));

        return $lotWalletHash !== '' && hash_equals($lotWalletHash, $hash);
    }

    private static function requestIsSellerPlacedLotsQuery(Request $request): bool
    {
        $wa = $request->input('wallet_address') ?? $request->input('wallet_address[]');
        if (empty($wa)) {
            return false;
        }
        $addresses = is_array($wa) ? $wa : [$wa];
        foreach ($addresses as $a) {
            if (! is_string($a)) {
                continue;
            }
            $decoded = json_decode(trim($a), true);
            if (is_array($decoded) && ! empty($decoded['token_hash'])) {
                return true;
            }
        }

        return false;
    }

    /** API lot timestamps in UTC regardless of APP_TIMEZONE / DB session. */
    private static function toUtcIso8601(?CarbonInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value->clone()->utc()->toIso8601String();
    }

    /**
     * Currency type for amount formatting: "fiat" | "stablecoin" | "crypto".
     * Fiat and stablecoin use 2 decimals; crypto uses 8.
     */
    private static function currencyTypeForLotSide($lot, string $side): string
    {
        if ($side === 'from') {
            if ($lot->from_fiat_currency_id) {
                return 'fiat';
            }
            if ($lot->relationLoaded('fromCryptoCurrency') && $lot->fromCryptoCurrency) {
                return $lot->fromCryptoCurrency->is_native ? 'crypto' : 'stablecoin';
            }
            return 'crypto';
        }
        if ($lot->to_fiat_currency_id) {
            return 'fiat';
        }
        if ($lot->relationLoaded('toCryptoCurrency') && $lot->toCryptoCurrency) {
            return $lot->toCryptoCurrency->is_native ? 'crypto' : 'stablecoin';
        }
        if ($lot->relationLoaded('cryptoCurrency') && $lot->cryptoCurrency) {
            return $lot->cryptoCurrency->is_native ? 'crypto' : 'stablecoin';
        }
        return 'crypto';
    }

    /**
     * Format crypto code for display: e.g. usdt_tron → USDT (Tron).
     */
    private static function formatCryptoCodeForDisplay(string $code): string
    {
        if (str_contains($code, '_')) {
            $parts = explode('_', $code, 2);
            return strtoupper($parts[0]) . ' (' . ucfirst(strtolower($parts[1])) . ')';
        }
        return strtoupper($code);
    }
}
