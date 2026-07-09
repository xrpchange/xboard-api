<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealCryptoPayoutJob extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'exchange_lot_id',
        'transaction_id',
        'seller_escrow_token_public',
        'crypto_type',
        'status',
        'attempts',
        'max_attempts',
        'next_attempt_at',
        'last_error',
        'crypto_tx_id',
        'aml_required',
    ];

    protected function casts(): array
    {
        return [
            'exchange_lot_id' => 'integer',
            'transaction_id' => 'integer',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'aml_required' => 'boolean',
        ];
    }

    public function exchangeLot(): BelongsTo
    {
        return $this->belongsTo(ExchangeLot::class, 'exchange_lot_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
}
