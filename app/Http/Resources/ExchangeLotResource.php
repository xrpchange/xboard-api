<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ExchangeLotResource extends JsonResource
{
    public function toArray($request)
    {
        $buyAmounts = $this->buy_amounts ?? [];
        $sellAmount = (float) $this->sell_amount;
        $rate = $sellAmount > 0 && count($buyAmounts) > 0
            ? (array_sum($buyAmounts) / count($buyAmounts)) / $sellAmount
            : 0;

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
            'sell_amount' => (float) $this->sell_amount,
            'buy_amount_min' => (float) $this->buy_amount_min,
            'buy_amount_max' => (float) $this->buy_amount_max,
            'buy_amounts' => $buyAmounts,
            'rate' => (float) $rate,
            'status' => $status,
            'crypto_currency_id' => $this->crypto_currency_id,
            'wallet_address' => $this->wallet_address ?? '',
            'bank_id' => $this->bank_id,
            'note' => $this->note ?? '',
            'created_at' => $this->created_at?->toISOString() ?? now()->toISOString(),
            'expires_at' => $this->expires_at?->toISOString() ?? '',
        ];
    }
}
