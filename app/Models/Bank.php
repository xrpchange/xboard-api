<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @OA\Schema(
 *     schema="Bank",
 *     title="Bank",
 *     description="Bank model"
 * )
 */

class Bank extends Model
{
    use HasFactory;

    protected $fillable = [
        'country_id',
        'name',
        'swift_code',
        'supports_individuals',
        'supports_legal_entities',
        'is_active',
    ];

    /**
     * @OA\Property(property="id", type="integer", example=1)
     * @OA\Property(property="country_id", type="integer", example=1)
     * @OA\Property(property="name", type="string", example="Sberbank")
     * @OA\Property(property="swift_code", type="string", example="SABRRUMM")
     * @OA\Property(property="supports_individuals", type="boolean", example=true)
     * @OA\Property(property="supports_legal_entities", type="boolean", example=true)
     * @OA\Property(property="is_active", type="boolean", example=true)
     * @OA\Property(property="created_at", type="string", format="date-time")
     * @OA\Property(property="updated_at", type="string", format="date-time")
     */

    protected $casts = [
        'supports_individuals' => 'boolean',
        'supports_legal_entities' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Get bank's country
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * Фиатные валюты, которые поддерживает банк
     */
    public function fiatCurrencies(): BelongsToMany
    {
        return $this->belongsToMany(FiatCurrency::class, 'bank_fiat_currency')->withTimestamps();
    }

    /**
     * Получить активные банки
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Получить банки для физических лиц
     */
    public function scopeForIndividuals($query)
    {
        return $query->where('supports_individuals', true);
    }

    /**
     * Получить банки для юридических лиц
     */
    public function scopeForLegalEntities($query)
    {
        return $query->where('supports_legal_entities', true);
    }
} 