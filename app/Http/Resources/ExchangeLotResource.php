<?php

namespace App\Http\Resources;

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

        return [
            'id' => (string) $this->id,
            'node_id' => $this->node_id ? (string) $this->node_id : null,
            'node_domain' => $this->relationLoaded('node') ? ($this->node?->domain ?? null) : null,
            'from_currency' => $this->from_currency ?? '',
            'to_currency' => $this->to_currency ?? '',
            'from_currency_type' => self::currencyTypeForLotSide($this, 'from'),
            'to_currency_type' => self::currencyTypeForLotSide($this, 'to'),
            'sell_amount' => (float) $this->sell_amount,
            'buy_amount_min' => (float) $this->buy_amount_min,
            'buy_amount_max' => (float) $this->buy_amount_max,
            'buy_amounts' => $buyAmounts,
            'rate' => (float) $rate,
            'status' => $status,
            'crypto_currency_id' => $this->crypto_currency_id,
            'to_crypto_code' => $this->relationLoaded('cryptoCurrency') && $this->cryptoCurrency ? $this->cryptoCurrency->code : null,
            'to_crypto_display' => $this->relationLoaded('cryptoCurrency') && $this->cryptoCurrency ? self::formatCryptoCodeForDisplay($this->cryptoCurrency->code) : null,
            'wallet_address' => $this->wallet_address ?? '',
            'bank_id' => $this->bank_id,
            'bank_name' => $this->relationLoaded('bank') && $this->bank ? $this->bank->name : null,
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
            'aml' => $this->aml !== null ? (int) $this->aml : null,
            'buyer_payment_timeout_minutes' => isset($this->buyer_payment_timeout_minutes) ? (int) $this->buyer_payment_timeout_minutes : 30,
            'seller_notification_email' => $this->seller_notification_email ? (string) $this->seller_notification_email : null,
            'created_at' => $this->created_at?->toISOString() ?? now()->toISOString(),
            'expires_at' => $this->expires_at?->toISOString() ?? '',
        ];
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
