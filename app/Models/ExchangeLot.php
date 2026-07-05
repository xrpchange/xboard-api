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
        'virtual_sandbox_bank_code',
        'bank_account',
        'payment_purpose',
        'contract_url',
        'is_for_individuals',
        'is_for_legal_entities',
        'is_active',
        'is_test',
        'is_training',
        'test_group',
        'main_group',
        'note',
        'seller_locale',
        'buyer_lot_email',
        'buyer_seen_ip',
        'buyer_ip_share_with_seller',
        'buyer_escrow_token_hash',
        'buyer_selected_amount',
        'seller_lot_email',
        'aml',
        'buyer_payment_timeout_minutes',
        'seller_notification_email',
        'client_web_origin',
        'expires_at',
        'escrow_multisig_2_of_3_required',
        'sold_crypto_amount',
    ];

    protected $casts = [
        'sell_amount' => 'decimal:8',
        'sold_crypto_amount' => 'decimal:8',
        'completed_deals_count' => 'integer',
        'bad_deals_count' => 'integer',
        'buy_amount_min' => 'decimal:8',
        'buy_amount_max' => 'decimal:8',
        'buy_amounts' => 'array',
        'buyer_selected_amount' => 'float',
        'rate' => 'decimal:8',
        'is_for_individuals' => 'boolean',
        'is_for_legal_entities' => 'boolean',
        'is_active' => 'boolean',
        'is_test' => 'boolean',
        'is_training' => 'boolean',
        'aml' => 'boolean',
        'buyer_payment_timeout_minutes' => 'integer',
        'arbiter_ai_invocations' => 'integer',
        'expires_at' => 'datetime',
        'escrow_multisig_2_of_3_required' => 'boolean',
        'buyer_ip_share_with_seller' => 'boolean',
    ];

    /**
     * Do not leak buyer IP / consent in generic model serialization; ExchangeLotResource exposes them for sellers only.
     */
    protected $hidden = [
        'buyer_seen_ip',
        'buyer_ip_share_with_seller',
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

    /**
     * Same row selection as {@see \App\Http\Controllers\Api\ExchangeLotController::storeReceiptLinksInLatestTransaction}:
     * latest `transactions.id` for this lot, optionally scoped by `buyer_lot_email` when set.
     */
    public function latestReceiptTransaction(): ?Transaction
    {
        $query = Transaction::query()->where('exchange_lot_id', $this->id);
        if (! empty($this->buyer_lot_email)) {
            $query->where('buyer_lot_email', $this->buyer_lot_email);
        }

        return $query->orderByDesc('id')->first();
    }

    /**
     * Laravel {@see Transaction} id for the active deal leg (latest row for this lot / current buyer).
     * Same selection as {@see latestReceiptTransaction()}.
     */
    public function primaryDealTransactionId(): ?int
    {
        $tx = $this->latestReceiptTransaction();

        return $tx !== null ? (int) $tx->id : null;
    }

    public function dealChatMessages(): HasMany
    {
        return $this->hasMany(DealChatMessage::class);
    }

    public function dealDisputes(): HasMany
    {
        return $this->hasMany(DealDispute::class);
    }

    /**
     * Total completed sale volume already accumulated on the lot.
     * Use for "sold amount" / "remaining = sell_amount - sold_amount".
     */
    public function getSoldAmountAttribute(): float
    {
        return (float) ($this->sold_crypto_amount ?? 0);
    }

    /**
     * Remaining "from" side volume (sell_amount minus accumulated completed sales).
     */
    public function getRemainingAmountAttribute(): float
    {
        return max(0.0, (float) $this->sell_amount - (float) ($this->sold_crypto_amount ?? 0));
    }

    /**
     * Positive buy-side tier amounts configured on the lot (to currency).
     *
     * @return list<float>
     */
    public function configuredBuyAmounts(): array
    {
        $raw = $this->buy_amounts ?? [];
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map('floatval', $raw), fn ($v) => $v > 0));
    }

    /**
     * Buy-side tier amounts (to currency) that still fit into {@see getRemainingAmountAttribute} at the lot rate.
     *
     * @return list<float>
     */
    public function availableBuyAmountsForRemaining(): array
    {
        $amounts = $this->configuredBuyAmounts();
        if ($amounts === []) {
            return [];
        }
        $remaining = max(0.0, (float) $this->sell_amount - (float) ($this->sold_crypto_amount ?? 0));
        if ($remaining <= 0) {
            return [];
        }
        $rate = isset($this->rate) ? (float) $this->rate : 0.0;
        if ($rate <= 0 && count($amounts) === 1) {
            $sell = (float) $this->sell_amount;
            $rate = $sell > 0 ? $amounts[0] / $sell : 0.0;
        }
        if ($rate <= 0) {
            return [];
        }
        $tol = max(1e-9, 1e-12 * max($remaining, 1.0));
        $out = [];
        foreach ($amounts as $b) {
            $fromNeeded = $b / $rate;
            if ($fromNeeded <= $remaining + $tol) {
                $out[] = $b;
            }
        }

        return $out;
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
