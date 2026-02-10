<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'from_currency',
        'to_currency',
        'sell_amount',
        'buy_amount_min',
        'buy_amount_max',
        'buy_amounts',
        'crypto_currency_id',
        'wallet_address',
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
        'buy_amount_min' => 'decimal:8',
        'buy_amount_max' => 'decimal:8',
        'buy_amounts' => 'array',
        'is_for_individuals' => 'boolean',
        'is_for_legal_entities' => 'boolean',
        'is_active' => 'boolean',
        'is_test' => 'boolean',
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
     * Криптовалюта лота (из Core)
     */
    public function cryptoCurrency(): BelongsTo
    {
        return $this->belongsTo(CryptoCurrency::class);
    }

    /**
     * Банк (опционально)
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
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

    public function scopeTest($query, $testGroup = null)
    {
        $query = $query->where('is_test', true);
        if ($testGroup) {
            $query->where('test_group', $testGroup);
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
