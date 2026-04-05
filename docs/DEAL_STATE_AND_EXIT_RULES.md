# Deal state and exit rules (API)

Deal state and who can exit are defined **by the lot fields** (table `exchange_lots`) and **by transactions tied to the buyer** (field `transactions.buyer_lot_email`). Blocking is **per buyer**, not for the whole lot, so that in future a lot can have multiple buyers.

Additionally, deal exit/cancel is protected by a **transaction-level timeout window**. The API must not trust only client-side state.

## State model

- **Is there a buyer in the deal?** — Field **`buyer_lot_email`** on the lot. Non-empty = there is a current buyer for this lot (today one buyer per lot; later multiple buyers may be supported).
- **Has the seller sent payment details to this buyer?** — Each row in `transactions` has **`buyer_lot_email`** (the buyer this transaction was sent to). We only consider transactions where `buyer_lot_email` equals the lot’s current buyer. So: “payment details sent for this deal” = at least one transaction for this lot **and** this buyer.

## Exit rules (per buyer)

| Lot state | Who can exit / cancel |
|-----------|------------------------|
| `buyer_lot_email` empty | No deal; exit not applicable. |
| `buyer_lot_email` set, no pending sale transactions for **this buyer** on this lot | Exit/cancel allowed by existing role rules. |
| `buyer_lot_email` set, there are pending sale transactions and **at least one transaction timeout is not finished yet** | **Seller** cannot use `seller_exited` (403 `deal_exit_locked_by_transaction_timeout`). **Buyer** can still call `buyer-cancel-deal` (not blocked by this window). |
| `buyer_lot_email` set, pending sale transactions exist but all their timeouts have expired | **Seller** `seller_exited`: may still be restricted by `payment_details_set_by` (403 `deal_exit_not_allowed_for_side` when the rule says only the other side). **Buyer** `buyer-cancel-deal`: allowed (token-authorised buyer cancel does not use timeout / payment-details-side gates). |

Timeout is checked **per transaction**, not as one common timer for the whole deal:

- For each pending sale transaction, API uses `transactions.transaction_timeout_minutes` if present.
- Fallback for older rows: `exchange_lots.buyer_payment_timeout_minutes` (default `30`).
- Timer starts from `transactions.created_at`.
- If any pending transaction still has remaining time, exit/cancel is blocked.

So deal lock is still **per buyer** and now also **per transaction time window**. Other buyers on the same lot (if introduced later) are not affected.

## Implementation notes

- **transactions.buyer_lot_email**: Set when creating a transaction in `sendPaymentDetails` (from `$lot->buyer_lot_email`). Used to filter transactions by buyer.
- **sendMessageToBuyer** (seller_exited): We require `buyer_lot_email` on the lot (otherwise 400). We block with 403 `deal_exit_locked_by_transaction_timeout` if there is at least one pending sale transaction for this lot+buyer where timeout has not passed yet.
- **buyer-cancel-deal**: Buyer is authorised by matching requestor’s token hash to `lot.buyer_escrow_token_hash`; then we delete only **this buyer’s** transactions (`where('buyer_lot_email', $lot->buyer_lot_email)`), call Core to delete reservations, clear `buyer_lot_email`, and notify the seller. **No** `deal_exit_locked_by_transaction_timeout` / `deal_exit_not_allowed_for_side` checks here — those apply to **seller** exit only.
- **After timeout ownership rule (seller exit only):** For `seller_exited`, API checks who provided payment details (`transactions.payment_details_set_by`). Only the allowed side can exit after the timeout window; otherwise 403 `deal_exit_not_allowed_for_side`.
- **Core URL for reservations:** When calling Core (`/api/reservations`, `/api/reservations/set-transaction-id`, `/api/cancel-reservation-by-transaction`), the API uses **the escrow token’s environment** to choose Core URL and key via `EscrowTokenValidationService::getCoreUrlAndKeyForToken($escrowToken)`. So the same Core (and TOKEN_HASH_SALT) as at token creation is used. See `docs/ESCROW_TOKEN_AUTHENTICATION.md` (§ Calling Core API: URL by token environment).
- **Amicable completion and Core reservations:** When the buyer sends chat tag `deal_completed_buyer` (`send-message-to-seller`) or the seller sends `deal_completed_seller_timeout` (`send-message-to-buyer`), the API marks pending `sale` transactions `completed` and **best-effort** calls Core to release the escrow reservation: buyer path → `POST /api/delete-reservation-by-buyer` with the buyer escrow token from the request (`escrow_token` body or `X-Escrow-Token` header); seller timeout path → `POST /api/delete-reservation` with the seller token the same way. If no token is sent, Core is skipped (info log). HTTP failures are warning-logged only and do not fail the chat message (same pattern as `buyer_exited` / `seller_exited`). See `docs/SPEC_REGISTRY.md` LOT-003.

See also client doc: `xboard-client/docs/DEAL_IN_PROGRESS_SCREENS.md` (§ 5).

## Buyer deal restore approach (final test scope)

This section defines the restore approach that must be validated on final tests.

- **Source of truth is server DB, not device memory.** App startup must ask API for active buyer deals even if local deal state is empty.
- **Identity for restore is strict token hash match.** API endpoints `GET /exchange-lots/buyer-active-deal` and `GET /exchange-lots/buyer-active-deals` search by `exchange_lots.buyer_escrow_token_hash` using `token_hashes` from client.
- **Client sends all known buyer token hashes per node in one request.** No per-token loop; one batched request per exchange node/environment.
- **Lock is set at deal start in one flow.** On `start_deal`, API must set buyer mapping on lot (`buyer_lot_email`, `buyer_escrow_token_hash`, optional `buyer_selected_amount`) during the same operation that starts the deal.
- **Mailbox availability must not silently skip lock.** If buyer mailbox cannot be resolved/created for `start_deal`, API returns explicit error (`buyer_mailbox_unavailable`) instead of creating partial/ambiguous deal state.
- **Restore result handling on client:** open first active deal automatically for buyer; also show list of unfinished deals for manual open from Search screen.

### What to verify in final testing

- Start deal as buyer, kill app, reopen app: buyer is restored to active deal from server.
- Start deal on one device, reopen on another device with same buyer token set: active deal is restored.
- Lot in partial payment state is returned by `buyer-active-deal(s)` when hash matches `buyer_escrow_token_hash`.
- If `start_deal` cannot create/resolve mailbox, API returns `buyer_mailbox_unavailable` and does not leave half-locked lot state.
