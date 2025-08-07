<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'phone_code',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Получить банки в этой стране
     */
    public function banks(): HasMany
    {
        return $this->hasMany(Bank::class);
    }

    /**
     * Получить активные страны
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
} 