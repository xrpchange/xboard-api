<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserToken extends Model
{
    protected $fillable = [
        'token',
        'device_id',
        'collateral_token',
        'escrow_token_hash', // Hash of escrow token public part
        'role',
        'ip_address',
        'last_used_at',
        'expires_at'
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime'
    ];
    
    /**
     * Проверка, является ли пользователь админом
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
    
    /**
     * Проверка, является ли пользователь разработчиком
     */
    public function isDeveloper(): bool
    {
        return in_array($this->role, ['developer', 'admin']);
    }

    /**
     * Get associated exchange lot
     */
    public function exchangeLot(): BelongsTo
    {
        return $this->belongsTo(ExchangeLot::class, 'device_id', 'wallet_address');
    }

    /**
     * Check if token is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Update last used time
     */
    public function updateLastUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }
}
