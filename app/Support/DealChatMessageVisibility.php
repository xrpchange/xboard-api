<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Per-side mailbox clear must not soft-delete deal progress tags (client replays tags for "Deal steps").
 */
final class DealChatMessageVisibility
{
    /**
     * Rows safe to soft-delete when the user clears mailbox (plain chat only, not deal_tag).
     */
    public static function applyClearableByRequester(Builder $query, bool $isSeller): Builder
    {
        $column = $isSeller ? 'deleted_by_seller_at' : 'deleted_by_buyer_at';

        return $query->whereNull($column)
            ->where(function (Builder $q): void {
                $q->whereNull('deal_tag')
                    ->orWhere('deal_tag', '');
            });
    }
}
