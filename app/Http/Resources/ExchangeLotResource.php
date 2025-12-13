<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ExchangeLotResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        // Get currency symbols
        $fromCurrency = null;
        $toCurrency = null;
        
        if ($this->sell_currency_type === 'fiat') {
            $fromCurrency = $this->sellCurrency?->code ?? 'UNKNOWN';
        } elseif ($this->sell_currency_type === 'crypto') {
            $fromCurrency = $this->sellCryptoCurrency?->code ?? 'UNKNOWN';
        }
        
        if ($this->buy_currency_type === 'fiat') {
            $toCurrency = $this->buyCurrency?->code ?? 'UNKNOWN';
        } elseif ($this->buy_currency_type === 'crypto') {
            $toCurrency = $this->buyCryptoCurrency?->code ?? 'UNKNOWN';
        }
        
        // Calculate rate (buy_amount / sell_amount)
        $rate = $this->sell_amount > 0 ? ($this->buy_amount / $this->sell_amount) : 0;
        
        // Determine status
        $status = 'active';
        if (!$this->is_active) {
            $status = 'inactive';
        } elseif ($this->expires_at && $this->expires_at->isPast()) {
            $status = 'expired';
        }
        
        // Get node_id (default to 1 for now, or from relationship if exists)
        $nodeId = $this->node_id ?? '1';
        
        return [
            'id' => (string) $this->id,
            'node_id' => (string) $nodeId,
            'from_currency' => $fromCurrency ?? 'UNKNOWN',
            'to_currency' => $toCurrency ?? 'UNKNOWN',
            'amount' => (float) $this->sell_amount,
            'rate' => (float) $rate,
            'min_amount' => (float) ($this->sell_amount * 0.1), // 10% of amount as min
            'max_amount' => (float) $this->sell_amount,
            'status' => $status,
            'created_at' => $this->created_at?->toISOString() ?? now()->toISOString(),
            'expires_at' => $this->expires_at?->toISOString() ?? '',
            'wallet_address' => $this->wallet_address ?? '',
        ];
    }
}

