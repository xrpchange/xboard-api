<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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