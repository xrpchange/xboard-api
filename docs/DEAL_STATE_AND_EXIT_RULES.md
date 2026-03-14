# Deal state and exit rules (API)

Deal state and who can exit are defined **by the lot fields** (table `exchange_lots`) and **by transactions tied to the buyer** (field `transactions.buyer_lot_email`). Blocking is **per buyer**, not for the whole lot, so that in future a lot can have multiple buyers.

## State model

- **Is there a buyer in the deal?** — Field **`buyer_lot_email`** on the lot. Non-empty = there is a current buyer for this lot (today one buyer per lot; later multiple buyers may be supported).
- **Has the seller sent payment details to this buyer?** — Each row in `transactions` has **`buyer_lot_email`** (the buyer this transaction was sent to). We only consider transactions where `buyer_lot_email` equals the lot’s current buyer. So: “payment details sent for this deal” = at least one transaction for this lot **and** this buyer.

## Exit rules (per buyer)

| Lot state | Who can exit / cancel |
|-----------|------------------------|
| `buyer_lot_email` empty | No deal; exit not applicable. |
| `buyer_lot_email` set, no transactions for **this buyer** on this lot | **Seller** can exit: `sendMessageToBuyer` with `message_type = seller_exited` clears `buyer_lot_email` and notifies the buyer. |
| `buyer_lot_email` set, at least one transaction for **this buyer** on this lot | **Only this buyer** can cancel: `POST /exchange-lots/{lot}/buyer-cancel-deal`. Requests with `message_type = seller_exited` are rejected with 403. |

So the rule “seller cannot exit” is **per buyer**: we only block when there is at least one transaction for the **current** buyer (`exchange_lot_id` + `buyer_lot_email`). Other buyers on the same lot (if introduced later) are not affected.

## Implementation notes

- **transactions.buyer_lot_email**: Set when creating a transaction in `sendPaymentDetails` (from `$lot->buyer_lot_email`). Used to filter transactions by buyer.
- **sendMessageToBuyer** (seller_exited): We require `buyer_lot_email` on the lot (otherwise 400). We block with 403 only if there exists a transaction for this lot **and** this buyer: `Transaction::where('exchange_lot_id', $lot->id)->where('buyer_lot_email', $lot->buyer_lot_email)->exists()`.
- **buyer-cancel-deal**: Buyer is authorised by matching requestor’s token hash to `lot.buyer_escrow_token_hash`; then we delete only **this buyer’s** transactions (`where('buyer_lot_email', $lot->buyer_lot_email)`), call Core to delete reservations, clear `buyer_lot_email`, and notify the seller.
- **Core URL for reservations:** When calling Core (`/api/reservations`, `/api/reservations/set-transaction-id`, `/api/cancel-reservation-by-transaction`), the API uses **the escrow token’s environment** to choose Core URL and key via `EscrowTokenValidationService::getCoreUrlAndKeyForToken($escrowToken)`. So the same Core (and TOKEN_HASH_SALT) as at token creation is used. See `docs/ESCROW_TOKEN_AUTHENTICATION.md` (§ Calling Core API: URL by token environment).

See also client doc: `xboard-client/docs/DEAL_IN_PROGRESS_SCREENS.md` (§ 5).
