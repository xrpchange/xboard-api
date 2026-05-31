<?php

namespace App\Support;

use App\Models\ExchangeLot;

/**
 * After a terminal deal-completion tag, release the lot so it can appear in public search again.
 * Keeps buyer_escrow_token_hash for chat auth (empty thread) per purge contract.
 */
final class DealTerminalLotClosure
{
    public static function releaseLotBuyerBinding(ExchangeLot $lot): void
    {
        ExchangeLot::query()->where('id', $lot->id)->update([
            'buyer_lot_email' => null,
            'buyer_selected_amount' => null,
            'buyer_seen_ip' => null,
            'buyer_ip_share_with_seller' => false,
        ]);
    }
}
