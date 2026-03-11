<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Лот обмена. Соответствует экранному представлению создания лота.
 *
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
        'node_id',
        'from_fiat_currency_id',
        'from_crypto_currency_id',
        'to_fiat_currency_id',
        'to_crypto_currency_id',
        'sell_amount',
        'buy_amount_min',
        'buy_amount_max',
        'buy_amounts',
        'rate',
        'crypto_currency_id',
        'wallet_address',
        'wallet_hash',
        'bank_id',
        'bank_account',
        'payment_purpose',
        'contract_url',
        'is_for_individuals',
        'is_for_legal_entities',
        'is_active',
        'is_test',
        'test_group',
        'main_group',
        'note',
        'seller_locale',
        'buyer_lot_email',
        'buyer_selected_amount',
        'seller_lot_email',
        'aml',
        'expires_at',
    ];

    protected $casts = [
        'sell_amount' => 'decimal:8',
        'buy_amount_min' => 'decimal:8',
        'buy_amount_max' => 'decimal:8',
        'buy_amounts' => 'array',
        'buyer_selected_amount' => 'float',
        'rate' => 'decimal:8',
        'is_for_individuals' => 'boolean',
        'is_for_legal_entities' => 'boolean',
        'is_active' => 'boolean',
        'is_test' => 'boolean',
        'aml' => 'integer',
        'expires_at' => 'datetime',
    ];

    /**
     * Узел, к которому привязан лот
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * Валюта «отдаём» — фиат (ровно один из from_fiat / from_crypto задан).
     */
    public function fromFiatCurrency(): BelongsTo
    {
        return $this->belongsTo(FiatCurrency::class, 'from_fiat_currency_id');
    }

    public function fromCryptoCurrency(): BelongsTo
    {
        return $this->belongsTo(CryptoCurrency::class, 'from_crypto_currency_id');
    }

    /**
     * Валюта «получаем» — фиат или крипта (ровно один из to_fiat / to_crypto задан).
     */
    public function toFiatCurrency(): BelongsTo
    {
        return $this->belongsTo(FiatCurrency::class, 'to_fiat_currency_id');
    }

    public function toCryptoCurrency(): BelongsTo
    {
        return $this->belongsTo(CryptoCurrency::class, 'to_crypto_currency_id');
    }

    /**
     * Код валюты «отдаём» (для API; из справочника по FK).
     */
    public function getFromCurrencyAttribute(): string
    {
        $fiat = $this->fromFiatCurrency;
        if ($fiat) {
            return $fiat->code ?? '';
        }
        $crypto = $this->fromCryptoCurrency;
        return $crypto ? (string) $crypto->code : '';
    }

    /**
     * Код валюты «получаем» (для API; из справочника по FK).
     */
    public function getToCurrencyAttribute(): string
    {
        $fiat = $this->toFiatCurrency;
        if ($fiat) {
            return $fiat->code ?? '';
        }
        $crypto = $this->toCryptoCurrency;
        return $crypto ? (string) $crypto->code : '';
    }

    /**
     * Криптовалюта лота (из Core)
     */
    public function cryptoCurrency(): BelongsTo
    {
        return $this->belongsTo(CryptoCurrency::class);
    }

    /**
     * Банк (опционально, legacy / primary)
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /**
     * Список банков, от которых принимаем фиат (доп. фильтр). Пусто = любые.
     */
    public function banks(): BelongsToMany
    {
        return $this->belongsToMany(Bank::class, 'exchange_lot_banks')->withTimestamps();
    }

    /**
     * Транзакции по лоту
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Фильтр по test_group. $testGroup = null — только лоты без группы (test_group IS NULL).
     */
    public function scopeTest($query, $testGroup = null)
    {
        $query = $query->where('is_test', true);
        if ($testGroup) {
            $query->where('test_group', $testGroup);
        } else {
            $query->whereNull('test_group');
        }
        return $query;
    }

    /**
     * Фильтр по main_group. $mainGroup = null — только лоты без группы (main_group IS NULL).
     */
    public function scopeMain($query, $mainGroup = null)
    {
        $query = $query->where('is_test', false);
        if ($mainGroup) {
            $query->where('main_group', $mainGroup);
        } else {
            $query->whereNull('main_group');
        }
        return $query;
    }

    public function scopeForIndividuals($query)
    {
        return $query->where('is_for_individuals', true);
    }

    public function scopeForLegalEntities($query)
    {
        return $query->where('is_for_legal_entities', true);
    }
}
