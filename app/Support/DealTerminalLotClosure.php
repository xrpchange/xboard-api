<?php

namespace App\Support;

use App\Models\ExchangeLot;

/**
 * After a terminal deal-completion tag, release the lot so it can appear in public search again.
 * Regular lots keep buyer_escrow_token_hash for chat auth (empty thread) per purge contract.
 * Training lots reset sold_crypto_amount for search volume but keep buyer_escrow_token_hash until
 * the next start_deal so the buyer can still read terminal chat tags (seller_transfer_tx,
 * deal_completed_buyer) and advance the client checklist.
 */
final class DealTerminalLotClosure
{
    public static function releaseLotBuyerBinding(ExchangeLot $lot): void
    {
        $update = [
            'buyer_lot_email' => null,
            'buyer_selected_amount' => null,
            'buyer_seen_ip' => null,
            'buyer_ip_share_with_seller' => false,
        ];
        if ((bool) ($lot->is_training ?? false)) {
            $update['sold_crypto_amount'] = 0;
        }
        ExchangeLot::query()->where('id', $lot->id)->update($update);
    }
}
