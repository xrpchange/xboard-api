<?php

namespace App\Models;

use App\Services\ReceiptAttachmentStorage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Schema(
 *     schema="Transaction",
 *     title="Transaction",
 *     description="Transaction model"
 * )
 */

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'tx_hash',
        'buyer_crypto_payout_tx_hash',
        'seller_crypto_payout_tx_hash',
        'aml_check_json',
        'tx_type',
        'from_address',
        'to_address',
        'amount',
        'token',
        'exchange_lot_id',
        'buyer_lot_email',
        'bank_id',
        'bank_details',
        'crypto_address',
        'payment_details_set_by',
        'transaction_timeout_minutes',
        'receipt_links_json',
        'status',
        'is_test',
        'test_group',
    ];

    /**
     * @OA\Property(property="id", type="integer", example=1)
     * @OA\Property(property="tx_hash", type="string", example="ABC123DEF456")
     * @OA\Property(property="tx_type", type="string", example="deposit")
     * @OA\Property(property="from_address", type="string", example="rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh")
     * @OA\Property(property="to_address", type="string", example="rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh")
     * @OA\Property(property="amount", type="number", format="float", example=100.00)
     * @OA\Property(property="token", type="string", example="escrow_token_123")
     * @OA\Property(property="exchange_lot_id", type="integer", example=1)
     * @OA\Property(property="status", type="string", example="completed")
     * @OA\Property(property="is_test", type="boolean", example=false)
     * @OA\Property(property="test_group", type="string", example="test_group_1")
     * @OA\Property(property="created_at", type="string", format="date-time")
     * @OA\Property(property="updated_at", type="string", format="date-time")
     */

    protected $casts = [
        'amount' => 'decimal:8',
        'receipt_links_json' => 'array',
        'aml_check_json' => 'array',
        'is_test' => 'boolean',
    ];

    /**
     * Get exchange lot
     */
    public function exchangeLot(): BelongsTo
    {
        return $this->belongsTo(ExchangeLot::class);
    }

    /**
     * Get active transactions
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Get test transactions
     */
    public function scopeTest($query, $testGroup = null)
    {
        $query = $query->where('is_test', true);
        if ($testGroup) {
            $query->where('test_group', $testGroup);
        }
        return $query;
    }

    /**
     * Get transactions by type
     */
    public function scopeByType($query, $type)
    {
        return $query->where('tx_type', $type);
    }

    /**
     * Get transactions by address
     */
    public function scopeByAddress($query, $address)
    {
        return $query->where(function ($q) use ($address) {
            $q->where('from_address', $address)
              ->orWhere('to_address', $address);
        });
    }

    protected static function booted(): void
    {
        static::created(function (Transaction $transaction): void {
            if ($transaction->exchange_lot_id !== null
                && $transaction->status === 'completed'
                && $transaction->tx_type === 'sale'
            ) {
                $amount = (float) $transaction->amount;
                if ($amount > 0) {
                    DB::table('exchange_lots')
                        ->where('id', $transaction->exchange_lot_id)
                        ->increment('sold_crypto_amount', $amount);
                }
            }
        });

        static::updated(function (Transaction $transaction): void {
            if ($transaction->exchange_lot_id !== null
                && $transaction->tx_type === 'sale'
                && $transaction->status === 'completed'
                && $transaction->wasChanged('status')
                && $transaction->getOriginal('status') !== 'completed'
            ) {
                $amount = (float) $transaction->amount;
                if ($amount > 0) {
                    DB::table('exchange_lots')
                        ->where('id', $transaction->exchange_lot_id)
                        ->increment('sold_crypto_amount', $amount);
                }
            }
        });

        static::deleting(function (Transaction $transaction): void {
            $links = $transaction->receipt_links_json;
            if (is_array($links) && count($links) > 0) {
                ReceiptAttachmentStorage::deleteFilesFromReceiptLinks($links);
            }
        });
    }
} 