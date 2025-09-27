<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @OA\Schema(
 *     schema="BuyTrigger",
 *     title="BuyTrigger",
 *     description="Buy trigger model"
 * )
 */

class BuyTrigger extends Model
{
    use HasFactory;

    protected $fillable = [
        'crypto_currency_id',
        'wallet_address',
        'buy_currency_type',
        'buy_currency_id',
        'buy_amount',
        'sell_currency_type',
        'sell_currency_id',
        'max_sell_amount',
        'country_id',
        'bank_id',
        'is_for_individuals',
        'is_for_legal_entities',
        'is_active',
        'is_test',
        'test_group',
    ];

    /**
     * @OA\Property(property="id", type="integer", example=1)
     * @OA\Property(property="crypto_currency_id", type="integer", example=1)
     * @OA\Property(property="wallet_address", type="string", example="rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh")
     * @OA\Property(property="buy_currency_type", type="string", example="crypto")
     * @OA\Property(property="buy_currency_id", type="integer", example=1)
     * @OA\Property(property="buy_amount", type="number", format="float", example=100.00)
     * @OA\Property(property="sell_currency_type", type="string", example="fiat")
     * @OA\Property(property="sell_currency_id", type="integer", example=1)
     * @OA\Property(property="max_sell_amount", type="number", format="float", example=1000.00)
     * @OA\Property(property="country_id", type="integer", example=1)
     * @OA\Property(property="bank_id", type="integer", example=1)
     * @OA\Property(property="is_for_individuals", type="boolean", example=true)
     * @OA\Property(property="is_for_legal_entities", type="boolean", example=true)
     * @OA\Property(property="is_active", type="boolean", example=true)
     * @OA\Property(property="is_test", type="boolean", example=false)
     * @OA\Property(property="test_group", type="string", example="test_group_1")
     * @OA\Property(property="created_at", type="string", format="date-time")
     * @OA\Property(property="updated_at", type="string", format="date-time")
     */

    protected $casts = [
        'buy_amount' => 'decimal:8',
        'max_sell_amount' => 'decimal:8',
        'is_for_individuals' => 'boolean',
        'is_for_legal_entities' => 'boolean',
        'is_active' => 'boolean',
        'is_test' => 'boolean',
    ];

    /**
     * Получить криптовалюту токена
     */
    public function cryptoCurrency(): BelongsTo
    {
        return $this->belongsTo(CryptoCurrency::class);
    }

    /**
     * Получить страну
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * Get bank
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /**
     * Get active triggers
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get test triggers
     */
    public function scopeTest($query, $testGroup = null)
    {
        $query = $query->where('is_test', true);
        if ($testGroup) {
            $query->where('test_group', $testGroup);
        }
        return $query;
    }

    /**
     * Get triggers for individuals
     */
    public function scopeForIndividuals($query)
    {
        return $query->where('is_for_individuals', true);
    }

    /**
     * Get triggers for legal entities
     */
    public function scopeForLegalEntities($query)
    {
        return $query->where('is_for_legal_entities', true);
    }
} 