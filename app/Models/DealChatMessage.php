<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Deal chat message. Written when API sends deal-related email (mail as event).
 * Client reads from this table for dialog; X-XBoard-Deal-Tag stored in deal_tag.
 * When mailbox is cleared, records are soft-deleted (deleted_at set), not removed.
 */
class DealChatMessage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'exchange_lot_id',
        'sender_side',
        'from_email',
        'to_email',
        'body',
        'deal_tag',
    ];

    public function exchangeLot(): BelongsTo
    {
        return $this->belongsTo(ExchangeLot::class, 'exchange_lot_id');
    }
}
