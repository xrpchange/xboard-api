<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deal dispute state for an exchange lot.
 * Chat messages remain in deal_chat_messages; this model tracks dispute lifecycle/status.
 */
class DealDispute extends Model
{
    protected $fillable = [
        'exchange_lot_id',
        'opened_by',
        'status',
        'opened_message_id',
        'reason_code',
        'resolved_by',
        'resolution',
        'arbiter_id',
        'arbiter_model_version',
        'arbiter_verdict',
        'opened_at',
        'resolved_at',
        'closed_at',
        'last_activity_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function exchangeLot(): BelongsTo
    {
        return $this->belongsTo(ExchangeLot::class, 'exchange_lot_id');
    }

    public function openedMessage(): BelongsTo
    {
        return $this->belongsTo(DealChatMessage::class, 'opened_message_id');
    }
}
