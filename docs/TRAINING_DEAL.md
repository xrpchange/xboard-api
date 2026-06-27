# Training deal (sandbox practice)

**Purpose:** Let a single user practice **real** fiat↔crypto deals in **Sandbox API** with an **automated treasury seller**, while the client also offers a **local walkthrough** (`DealLearningScreen`) without API.

## Layers

| Layer | Where | What |
|-------|--------|------|
| Walkthrough | Client only | `DealLearningScreen` — roles and `deal_tag` order; no lot started |
| Practice deal | Sandbox API + client | `exchange_lots.is_training = true` — server orchestrator performs seller steps |

## v1 deal shapes (two lots)

`php artisan training:sync-lots` upserts **both** directions on the same treasury USDT Stellar token:

| Lot | Direction (label) | First leg (buyer pays) | Second leg (seller pays) |
|-----|-------------------|------------------------|---------------------------|
| A (#9) | **Fiat → crypto** (USD→USDT) | USDT Stellar testnet | USD virtual sandbox bank |
| B (#10) | **Crypto → fiat** (USDT→USD) | USD virtual sandbox bank | USDT Stellar testnet |

Leg types for **`is_training`** lots: **first leg = `to_currency`**, **second leg = `from_currency`** (not the same as non-training §4.1 from/to). API: `TrainingDealLegSemantics`; client: `trainingBuyerFirstLeg*` / `trainingBuyerSecondLegReceivesCryptoOrNull`.

- **Buyer:** real device; own Stellar USDT escrow token for crypto legs (Fund via Core in app). Lots are synced with **`aml=true`** so on crypto-first legs the deal screen shows **Send crypto to seller** (server payout via buyer escrow token) instead of manual tx-id entry only.
- **Step timer:** synced training lots use **`buyer_payment_timeout_minutes = 7`** (per-step deal timer in the app).
- **Seller:** treasury escrow token on the lot; **`TrainingDealOrchestrator`** on sandbox host only

## Orchestrator chain (buyer tags → seller auto-reply)

**Fiat → crypto (lot A / #9 USD→USDT label)**

1. `start_deal` → seller `payment_details` (Stellar address from treasury public JSON)
2. `buyer_crypto_tx` → seller `seller_received_funds`
3. `buyer_destination_details` → virtual bank receipt + seller `seller_transfer_tx` → **auto** `deal_completed_buyer` + lot release. Post-completion cleanup dialog as a regular deal.

**Crypto → fiat (lot B / #10 USDT→USD label)**

1. `start_deal` → seller `payment_details` (virtual bank)
2. `receipt_confirmation` → seller `seller_received_funds`
3. `buyer_destination_details` → Core payout + seller `seller_transfer_tx` → **auto** `deal_completed_buyer` + lot release. Post-completion cleanup dialog as a regular deal.

After completion, `DealTerminalLotClosure` clears buyer email fields and resets **`sold_crypto_amount` to 0** so public search lists the practice lot again; **`buyer_escrow_token_hash` stays** until the next `start_deal` so the buyer client can still load chat tags through `deal_completed_buyer`. `completed_deals_count` is kept as history. `training:sync-lots` also resets `sold_crypto_amount` on refresh.

**Abandoned practice deals:** when the client lists training lots (`GET /exchange-lots?is_training=true`), the API unlocks lots whose **`start_deal`** is older than **15 minutes** (config `TRAINING_ABANDONED_DEAL_MINUTES`): clears buyer lock, resets `sold_crypto_amount`, best-effort Core seller `delete-reservation`. Same-buyer resume after that window starts a fresh session on the next `start_deal`.

Validators are **not** relaxed for normal lots. On **`is_training`** sandbox lots, **`buyer_crypto_tx`** / **`seller_transfer_tx`** skip on-chain Core verify (tag gets `core_tx_check.verified=true`, `training_auto`); the orchestrator then posts **`seller_received_funds`** without waiting for testnet confirmations.

Orchestrator runs only when:

- `TRAINING_DEAL_ENABLED=true`
- API host is sandbox (`sandbox.api.*`)
- `exchange_lots.is_training = true`

## API / env

**Preferred (server):** full client export in `storage/app/training_seller_token.json` (gitignored). Public JSON is derived automatically for the lot.

```env
TRAINING_DEAL_ENABLED=true
TRAINING_SELLER_NOTIFICATION_EMAIL=training-seller@xdexboard.com
TRAINING_LOT_NODE_ID=1
TRAINING_LOT_SELL_AMOUNT=5
TRAINING_LOT_BUY_AMOUNTS=5
TRAINING_LOT_NOTE_FIAT_CRYPTO=Training: USD to USDT (Stellar)
TRAINING_LOT_NOTE_CRYPTO_FIAT=Training: USDT (Stellar) to USD
TRAINING_BUYER_PAYMENT_TIMEOUT_MINUTES=7
TRAINING_ABANDONED_DEAL_MINUTES=15
```

Optional overrides instead of the token file:

```env
TRAINING_SELLER_ESCROW_PUBLIC_JSON={"token_hash":"…","currency":"USDT","wallet_address":"G…"}
TRAINING_SELLER_ESCROW_TOKEN={"public_part":…,"encrypted_part":…}
```

Seed or refresh the lots:

```bash
php artisan migrate
sudo -u www-data php artisan training:sync-lots
```

Treasury seller must be **funded** on sandbox Core (Stellar testnet USDT) so reservations and crypto-leg payouts succeed.

The orchestrator **re-uploads** `encrypted_part` to Core automatically before each seller crypto payout (Core deletes it after transfer). Operators do not run manual steps between user deals.

## Client

- Search: badge **Training** when `is_training` in lot JSON; **practice mode** (from deal learning) lists only `is_training=true` lots (both directions)
- Buyer deal screen: banner that seller responds automatically
- Settings / Search: `DealLearningScreen` promo and post-track CTA to Search; hub cards use vector arrow (not Unicode `→`) for web Skiko

## Spec

- **LOT-021** — `tests/Unit/TrainingDealOrchestratorTest.php` (`@group spec-LOT-021`)
