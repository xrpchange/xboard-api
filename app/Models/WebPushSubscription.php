<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebPushSubscription extends Model
{
    protected $fillable = [
        'client_id',
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
        'exchange_lot_id',
        'recipient_role',
        'node_key',
        'token_hash',
    ];

    public function exchangeLot(): BelongsTo
    {
        return $this->belongsTo(ExchangeLot::class, 'exchange_lot_id');
    }
}
