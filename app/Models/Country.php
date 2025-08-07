<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @OA\Schema(
 *     schema="Country",
 *     title="Country",
 *     description="Модель страны"
 * )
 */

class Country extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'phone_code',
        'is_active',
    ];

    /**
     * @OA\Property(property="id", type="integer", example=1)
     * @OA\Property(property="name", type="string", example="Россия")
     * @OA\Property(property="code", type="string", example="RUS")
     * @OA\Property(property="phone_code", type="string", example="+7")
     * @OA\Property(property="is_active", type="boolean", example=true)
     * @OA\Property(property="created_at", type="string", format="date-time")
     * @OA\Property(property="updated_at", type="string", format="date-time")
     */

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