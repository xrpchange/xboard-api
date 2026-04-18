# Specification registry (API)

**Purpose:** Link **documented behavior** to **automated tests**. When you add or change a feature described in `docs/`, add a row here and a test (or mark verification as manual until a test exists).

**Run all spec-linked tests:**

```bash
php artisan test --group spec
```

**Run one requirement:**

```bash
php artisan test --group spec-ARB-004
```

## ID format

- **`ARB-*`** — AI dispute arbiter (`docs/AI_ARBITER_SERVER.md`).
- **`API-*`** — General HTTP API contracts (health, etc.).
- Future areas: e.g. `PAY-*` for payments, `LOT-*` for exchange lots (add sections below).

## Exchange lots

| ID | Document / route | Behavior (short) | Test(s) | Notes |
|----|------------------|------------------|---------|--------|
| LOT-001 | `POST /api/v1/exchange-lots/{lot}/purge-completed-deal-data` | Route registered (participant purge after `deal_completed_buyer`) | `tests/Feature/PurgeCompletedDealRouteTest.php` | Purge clears chat, mail, receipt files + nulls receipt JSON; **keeps** `transactions` for audit/history while they are still fresh, but lot accounting itself lives in `exchange_lots.sold_crypto_amount`, which is accumulated when sale transactions become `completed`. Clears `buyer_lot_email` / `buyer_selected_amount`; **keeps** `buyer_escrow_token_hash` so `GET chat-messages` still authorizes the same buyer token and returns an empty list (avoids 403 + stale client UI). Optional JSON `purge_notification_subject` / `purge_notification_body` (client-localized; chosen from `seller_locale`) — server forwards as notification email; if either missing, fixed English fallback. |
| LOT-002 | Buyer `POST …/send-message` with `deal_tag` `deal_completed_buyer` | Pending `sale` rows for that lot → `status=completed` | `tests/Unit/DealSaleTransactionCompletionTest.php` | Implemented in `App\Support\DealSaleTransactionCompletion`; runs after successful mail + `insertDealChatMessage`. |
| LOT-003 | Amicable completion tags (`deal_completed_buyer`, `deal_completed_seller_timeout`) | After marking `sale` completed, API best-effort calls Core `complete-reservation-by-buyer` (buyer token) or `complete-reservation` (seller token); Core releases reservation hold and transfers stored service fee to project wallet before cleanup. Token from body/header as for exit/cancel. | — | `ExchangeLotController` (`tryDeleteCoreReservationAfterBuyerAmicableCompletion`, `tryDeleteCoreReservationAfterSellerAmicableCompletion`). Documented in `docs/DEAL_STATE_AND_EXIT_RULES.md`. Add HTTP feature test when a minimal lot + auth harness exists. |
| LOT-004 | `POST …/send-message`, `POST …/send-message-to-buyer`, `POST …/send-payment-details` | Deal `deal_tag` progression matches client `DealChatTagState` (no skipping first-leg proof, no buyer completion before seller payout); `deal_tag` from client cannot override `start_deal` / `buyer_exited` / `seller_exited`. Payment details blocked in terminal chat states. | `tests/Unit/DealChatTagSequenceValidatorTest.php` | `App\Support\DealChatTagSequenceValidator`; wired in `ExchangeLotController`. |

## General API

| ID | Document / route | Behavior (short) | Test(s) | Notes |
|----|------------------|------------------|---------|--------|
| API-001 | `GET /api/v1/health` | `success`, `data.status` in `ok` \| `degraded` | `tests/Feature/ApiV1HealthTest.php` | |

## Arbiter (`docs/AI_ARBITER_SERVER.md`)

| ID | Document section | Behavior (short) | Test(s) | Notes |
|----|------------------|------------------|---------|--------|
| ARB-001 | Rules-first policy §4 (gray zone) | Sufficient deterministic context → LLM path → `arbiter_status=in_review`, `reason_code=ai_generated` | `tests/Unit/ArbiterServiceStubTest.php` (`test_spec_arb_001_sufficient_context_ai_path`) | Uses `StubArbiterProvider` (no HTTP). |
| ARB-002 | Rules-first policy §2 | `has_replay` true → `manual_review`, `anti_replay_triggered`, no provider | `tests/Unit/ArbiterServiceStubTest.php` (`test_spec_arb_002_anti_replay`) | Controller sets `has_replay` from DB; service contract tested here. |
| ARB-003 | Architecture + container wiring | Laravel resolves `ArbiterService` with `stub` provider under PHPUnit env | `tests/Feature/ArbiterServiceBindingsTest.php` | |
| ARB-004 | Rules-first policy §3 | Confidence ≤ `no_ai_below` → `insufficient_context`, no provider | `tests/Unit/ArbiterServiceStubTest.php` (`test_spec_arb_004_insufficient_context`) | |
| ARB-005 | Rules-first policy §5 | After LLM, if confidence &lt; manual threshold → `low_confidence`, English escalation suffix | `tests/Unit/ArbiterServiceStubTest.php` (`test_spec_arb_005_low_confidence_escalation`) | |
| ARB-006 | Rules-first policy §6 | All providers fail → `provider_unavailable` | `tests/Feature/ArbiterServiceBindingsTest.php` (`test_spec_arb_006_all_providers_fail`) | Uses Laravel `Log` facade (Feature test, not pure Unit). |
| ARB-007 | Testing checklist §7 + `deal_disputes` | `GET dispute-status` still sees `in_review` row after arbiter | — | **Manual / integration** (needs lot + dispute + HTTP). Add `Feature` test when harness exists. |
| ARB-008 | Security model | `provider_hint` not used for routing | — | **Manual** (curl checklist §2) or future HTTP feature test. |
| ARB-009 | `resources/prompts/arbiter_exchange_rules.md` + `ArbiterPromptContextAssembler` | Arbiter LLM user prompt includes rules digest, `deal_machine_state`, and requester-visible `recent_chat_digest` | `tests/Feature/ArbiterExchangeRulesFileTest.php` | Does not assert provider output; file presence + `loadRulesDigest()` smoke. `docs/ARBITER_EXCHANGE_RULES.md` is a pointer stub only. |

## Workflow for contributors

1. Describe behavior in the relevant `docs/*.md` file.
2. Add a row to this table with a new ID (next free in that area).
3. Add or update a PHPUnit test; tag with `@group spec` and `@group spec-<ID>`.
4. For behavior that needs full HTTP + DB, keep the test row empty in the **Test(s)** column until implemented, or reference a manual checklist step in **Notes**.
