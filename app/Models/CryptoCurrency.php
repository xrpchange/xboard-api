<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CryptoCurrency extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'is_stablecoin',
        'is_active',
    ];

    protected $casts = [
        'is_stablecoin' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Получить лоты обмена для этой криптовалюты
     */
    public function exchangeLots(): HasMany
    {
        return $this->hasMany(ExchangeLot::class);
    }

    /**
     * Получить триггеры покупки для этой криптовалюты
     */
    public function buyTriggers(): HasMany
    {
        return $this->hasMany(BuyTrigger::class);
    }

    /**
     * Получить активные криптовалюты
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Получить только стейблкоины
     */
    public function scopeStablecoins($query)
    {
        return $query->where('is_stablecoin', true);
    }
} 