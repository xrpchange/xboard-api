# Training deal (sandbox practice)

**Purpose:** Let a single user practice a **real** crypto→crypto deal in **Sandbox API** with an **automated treasury seller**, while the client also offers a **local walkthrough** (`DealLearningScreen`) without API.

## Layers

| Layer | Where | What |
|-------|--------|------|
| Walkthrough | Client only | `DealLearningScreen` — roles and `deal_tag` order; no lot started |
| Practice deal | Sandbox API + client | `exchange_lots.is_training = true` — server orchestrator performs seller steps |

## v1 deal shape

- **Direction:** crypto → crypto  
- **Asset:** USDT Stellar testnet (`usdt_stellar`) on both legs  
- **Buyer:** real device; own Stellar USDT escrow token (Fund via Core friendbot in app)  
- **Seller:** treasury escrow token on the lot; **`TrainingDealOrchestrator`** on sandbox host only  

## Orchestrator chain (buyer tags → seller auto-reply)

1. `start_deal` → seller `payment_details` (Stellar address from treasury public JSON)  
2. `buyer_crypto_tx` → seller `seller_received_funds`  
3. `buyer_destination_details` → Core `deal-payout-to-buyer-address` + seller `seller_transfer_tx`  
4. Buyer confirms `deal_completed_buyer` manually  

Validators are **not** relaxed for normal lots. Orchestrator runs only when:

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
TRAINING_LOT_NOTE=Для обучения
```

Optional overrides instead of the token file:

```env
TRAINING_SELLER_ESCROW_PUBLIC_JSON={"token_hash":"…","currency":"USDT","wallet_address":"G…"}
TRAINING_SELLER_ESCROW_TOKEN={"public_part":…,"encrypted_part":…}
```

Seed or refresh the lot:

```bash
php artisan migrate
php artisan training:sync-lots
```

Treasury seller must be **funded** on sandbox Core (Stellar testnet USDT) so reservations and second-leg payout succeed.

The orchestrator **re-uploads** `encrypted_part` to Core automatically before each seller payout (Core deletes it after transfer). Operators do not run manual steps between user deals.

## Client

- Search: badge **Training** when `is_training` in lot JSON; optional **practice mode** (from deal learning) lists only `is_training=true` USDT Stellar lots (`is_training` query param)  
- Buyer deal screen: banner that seller responds automatically  
- Settings / Search: `DealLearningScreen` promo and post-track CTA to Search  

## Spec

- **LOT-021** — `tests/Unit/TrainingDealOrchestratorTest.php` (`@group spec-LOT-021`)
