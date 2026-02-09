<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @OA\Schema(
 *     schema="CryptoCurrency",
 *     title="CryptoCurrency",
 *     description="Cryptocurrency model"
 * )
 */

class CryptoCurrency extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'code',
        'name',
        'is_native',
        'network',
        'is_active',
    ];

    /**
     * @OA\Property(property="id", type="integer", example=1)
     * @OA\Property(property="code", type="string", example="xrp")
     * @OA\Property(property="name", type="string", example="XRP (Ripple)")
     * @OA\Property(property="is_native", type="boolean", example=true)
     * @OA\Property(property="network", type="string", example="native")
     * @OA\Property(property="is_active", type="boolean", example=true)
     * @OA\Property(property="created_at", type="string", format="date-time")
     * @OA\Property(property="updated_at", type="string", format="date-time")
     */

    protected $casts = [
        'is_native' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Get exchange lots for this cryptocurrency
     */
    public function exchangeLots(): HasMany
    {
        return $this->hasMany(ExchangeLot::class);
    }

    /**
     * Get buy triggers for this cryptocurrency
     */
    public function buyTriggers(): HasMany
    {
        return $this->hasMany(BuyTrigger::class);
    }

    /**
     * Get active cryptocurrencies
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Find by code (case-insensitive). Core and client may send XRP or xrp, usdt_stellar etc.
     */
    public function scopeByCode($query, string $code)
    {
        return $query->whereRaw('LOWER(code) = ?', [strtolower($code)]);
    }

    /**
     * Get native chain coins (xrp, btc, xmr)
     */
    public function scopeNative($query)
    {
        return $query->where('is_native', true);
    }

    /**
     * Get only stablecoins (tokens on networks: !is_native)
     */
    public function scopeStablecoins($query)
    {
        return $query->where('is_native', false);
    }
} 