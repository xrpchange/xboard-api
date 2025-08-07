<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @OA\Schema(
 *     schema="ExchangeLot",
 *     title="ExchangeLot",
 *     description="Exchange lot model"
 * )
 */

class ExchangeLot extends Model
{
    use HasFactory;

    protected $fillable = [
        'crypto_currency_id',
        'wallet_address',
        'sell_currency_type',
        'sell_currency_id',
        'sell_amount',
        'buy_currency_type',
        'buy_currency_id',
        'buy_amount',
        'country_id',
        'bank_id',
        'bank_account',
        'payment_purpose',
        'contract_url',
        'is_for_individuals',
        'is_for_legal_entities',
        'is_active',
        'is_test',
        'test_group',
        'expires_at',
    ];

    /**
     * @OA\Property(property="id", type="integer", example=1)
     * @OA\Property(property="crypto_currency_id", type="integer", example=1)
     * @OA\Property(property="wallet_address", type="string", example="rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh")
     * @OA\Property(property="sell_currency_type", type="string", example="fiat")
     * @OA\Property(property="sell_currency_id", type="integer", example=1)
     * @OA\Property(property="sell_amount", type="number", format="float", example=1000.00)
     * @OA\Property(property="buy_currency_type", type="string", example="crypto")
     * @OA\Property(property="buy_currency_id", type="integer", example=1)
     * @OA\Property(property="buy_amount", type="number", format="float", example=100.00)
     * @OA\Property(property="country_id", type="integer", example=1)
     * @OA\Property(property="bank_id", type="integer", example=1)
     * @OA\Property(property="bank_account", type="string", example="1234567890")
     * @OA\Property(property="payment_purpose", type="string", example="Payment for crypto")
     * @OA\Property(property="contract_url", type="string", example="https://example.com/contract")
     * @OA\Property(property="is_for_individuals", type="boolean", example=true)
     * @OA\Property(property="is_for_legal_entities", type="boolean", example=true)
     * @OA\Property(property="is_active", type="boolean", example=true)
     * @OA\Property(property="is_test", type="boolean", example=false)
     * @OA\Property(property="test_group", type="string", example="test_group_1")
     * @OA\Property(property="expires_at", type="string", format="date-time")
     * @OA\Property(property="created_at", type="string", format="date-time")
     * @OA\Property(property="updated_at", type="string", format="date-time")
     */

    protected $casts = [
        'sell_amount' => 'decimal:8',
        'buy_amount' => 'decimal:8',
        'is_for_individuals' => 'boolean',
        'is_for_legal_entities' => 'boolean',
        'is_active' => 'boolean',
        'is_test' => 'boolean',
        'expires_at' => 'datetime',
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
     * Получить банк
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /**
     * Получить транзакции для этого лота
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Получить активные лоты
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Получить тестовые лоты
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
     * Получить лоты для физических лиц
     */
    public function scopeForIndividuals($query)
    {
        return $query->where('is_for_individuals', true);
    }

    /**
     * Получить лоты для юридических лиц
     */
    public function scopeForLegalEntities($query)
    {
        return $query->where('is_for_legal_entities', true);
    }
} 