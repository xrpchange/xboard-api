<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @OA\Schema(
 *     schema="FiatCurrency",
 *     title="FiatCurrency",
 *     description="Модель фиатной валюты"
 * )
 */

class FiatCurrency extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'is_active',
    ];

    /**
     * @OA\Property(property="id", type="integer", example=1)
     * @OA\Property(property="code", type="string", example="USD")
     * @OA\Property(property="name", type="string", example="US Dollar")
     * @OA\Property(property="symbol", type="string", example="$")
     * @OA\Property(property="is_active", type="boolean", example=true)
     * @OA\Property(property="created_at", type="string", format="date-time")
     * @OA\Property(property="updated_at", type="string", format="date-time")
     */

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Получить активные фиатные валюты
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
} 