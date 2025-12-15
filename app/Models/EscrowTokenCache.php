<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Escrow Token Cache Model
 * 
 * Simple cache for escrow token validation.
 * Stores publicPart and validation results to avoid calling Core API on every request.
 * 
 * This is much simpler than Bearer tokens:
 * - No expiration time management
 * - No refresh logic
 * - No token versioning
 * - Easier to maintain and migrate
 * 
 * Key: token_hash (part of escrow token, comes from Core API in publicPart)
 * Purpose: Fast token validation without Core API calls
 */
class EscrowTokenCache extends Model
{
    protected $table = 'escrow_token_cache';

    protected $fillable = [
        'token_hash', // Part of escrow token, extracted from publicPart - used as key
        // NOTE: public_part is NOT stored - it comes from client in every request
        'currency', // Extracted from publicPart for indexing/filtering
        'node_domain', // Extracted from publicPart for indexing/filtering
        'environment', // Extracted from publicPart for indexing/filtering
        'device_id',
        'ip_address',
        'last_validated_at',
        'last_used_at'
    ];

    protected $casts = [
        'last_validated_at' => 'datetime',
        'last_used_at' => 'datetime'
    ];

    /**
     * Find or create cache entry for publicPart
     * 
     * NOTE: public_part is NOT stored - it comes from client in every request.
     * We only cache validation metadata to avoid calling Core API on every request.
     * 
     * @param string $publicPart JSON string with public part of escrow token
     * @param string|null $deviceId Device ID (optional, for statistics)
     * @param string|null $ipAddress IP address (optional, for statistics)
     * @return self
     */
    public static function findOrCreate(string $publicPart, ?string $deviceId = null, ?string $ipAddress = null): self
    {
        // Extract token_hash from publicPart - it's already in the token from Core API
        // We don't calculate it, we just extract it
        $tokenData = json_decode($publicPart, true);
        if (!$tokenData || !isset($tokenData['token_hash'])) {
            throw new \InvalidArgumentException('Invalid publicPart: token_hash is missing');
        }
        
        $tokenHash = strtolower(trim($tokenData['token_hash']));
        
        // Try to find existing cache entry
        $cache = self::where('token_hash', $tokenHash)->first();
        
        if ($cache) {
            // Update last_used_at
            $cache->update(['last_used_at' => now()]);
            return $cache;
        }
        
        // Parse publicPart to extract metadata (publicPart itself is NOT stored)
        $tokenData = json_decode($publicPart, true);
        
        if (!$tokenData) {
            throw new \InvalidArgumentException('Invalid publicPart JSON');
        }
        
        // Create new cache entry with metadata only
        // publicPart is always available from client request, no need to store it
        return self::create([
            'token_hash' => $tokenHash,
            'currency' => $tokenData['currency'] ?? 'XRP',
            'node_domain' => $tokenData['node_domain'] ?? null,
            'environment' => $tokenData['environment'] ?? $tokenData['mode'] ?? 'sandbox',
            'device_id' => $deviceId,
            'ip_address' => $ipAddress,
            'last_validated_at' => null, // Will be set after Core API validation
            'last_used_at' => now()
        ]);
    }

    /**
     * Mark token as validated (after successful Core API check)
     */
    public function markAsValidated(): void
    {
        $this->update(['last_validated_at' => now()]);
    }

    /**
     * Check if token needs re-validation
     * Re-validate if last validation was more than 1 hour ago
     * 
     * @return bool
     */
    public function needsRevalidation(): bool
    {
        if (!$this->last_validated_at) {
            return true; // Never validated
        }
        
        return $this->last_validated_at->lt(now()->subHour());
    }
}

