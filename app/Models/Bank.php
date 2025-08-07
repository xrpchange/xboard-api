<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected $casts = [
        'supports_individuals' => 'boolean',
        'supports_legal_entities' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Получить страну банка
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
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