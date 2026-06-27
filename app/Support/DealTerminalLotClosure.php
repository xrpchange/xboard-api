<?php

namespace App\Support;

use App\Models\ExchangeLot;

/**
 * After a terminal deal-completion tag, release the lot so it can appear in public search again.
 * Regular lots keep buyer_escrow_token_hash for chat auth (empty thread) per purge contract.
 * Training lots also clear buyer_escrow_token_hash so the practice lot is fully reusable.
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
            $update['buyer_escrow_token_hash'] = null;
        }
        ExchangeLot::query()->where('id', $lot->id)->update($update);
    }
}
