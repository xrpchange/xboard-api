<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     * Получить банк
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /**
     * Получить активные триггеры
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Получить тестовые триггеры
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
     * Получить триггеры для физических лиц
     */
    public function scopeForIndividuals($query)
    {
        return $query->where('is_for_individuals', true);
    }

    /**
     * Получить триггеры для юридических лиц
     */
    public function scopeForLegalEntities($query)
    {
        return $query->where('is_for_legal_entities', true);
    }
} 