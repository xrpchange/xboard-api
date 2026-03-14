<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deal chat message. Written when API sends deal-related email (mail as event).
 * Client reads from this table for dialog; X-XBoard-Deal-Tag stored in deal_tag.
 * Clearing is per-side: seller clearing sets deleted_by_seller_at (hidden only for seller);
 * buyer clearing sets deleted_by_buyer_at (hidden only for buyer).
 */
class DealChatMessage extends Model
{
    protected $fillable = [
        'exchange_lot_id',
        'sender_side',
        'from_email',
        'to_email',
        'body',
        'deal_tag',
    ];

    protected $casts = [
        'deleted_by_seller_at' => 'datetime',
        'deleted_by_buyer_at' => 'datetime',
    ];

    public function exchangeLot(): BelongsTo
    {
        return $this->belongsTo(ExchangeLot::class, 'exchange_lot_id');
    }
}
