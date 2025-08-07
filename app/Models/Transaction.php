<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'tx_hash',
        'tx_type',
        'from_address',
        'to_address',
        'amount',
        'token',
        'exchange_lot_id',
        'status',
        'is_test',
        'test_group',
    ];

    protected $casts = [
        'amount' => 'decimal:8',
        'is_test' => 'boolean',
    ];

    /**
     * Получить лот обмена
     */
    public function exchangeLot(): BelongsTo
    {
        return $this->belongsTo(ExchangeLot::class);
    }

    /**
     * Получить активные транзакции
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Получить тестовые транзакции
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
     * Получить транзакции по типу
     */
    public function scopeByType($query, $type)
    {
        return $query->where('tx_type', $type);
    }

    /**
     * Получить транзакции по адресу
     */
    public function scopeByAddress($query, $address)
    {
        return $query->where(function ($q) use ($address) {
            $q->where('from_address', $address)
              ->orWhere('to_address', $address);
        });
    }
} 