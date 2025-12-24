<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class EmailAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'password_encrypted',
        'imap_host',
        'imap_port',
        'is_active',
        'last_checked_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'imap_port' => 'integer',
        'last_checked_at' => 'datetime',
    ];

    protected $hidden = [
        'password_encrypted',
    ];

    /**
     * Get decrypted password
     */
    public function getPasswordAttribute(): string
    {
        return Crypt::decryptString($this->password_encrypted);
    }

    /**
     * Set encrypted password
     */
    public function setPasswordAttribute(string $password): void
    {
        $this->password_encrypted = Crypt::encryptString($password);
    }

    /**
     * Get active accounts
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

