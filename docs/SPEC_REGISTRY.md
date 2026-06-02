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
| LOT-001 | `POST /api/v1/exchange-lots/{lot}/purge-completed-deal-data` | Route registered (participant purge after terminal deal tag) | `tests/Feature/PurgeCompletedDealRouteTest.php` | Purge deletes deal chat rows and lot mailbox files on the mail server. Optional JSON `delete_transactions` (default `true`): when `false`, **transaction rows** and receipt PDFs are kept until scheduled prune (~15 days); when `true`, transactions are deleted via model `delete()` so `Transaction::deleting` removes files from `receipt_links_json`. Clears `buyer_lot_email` / `buyer_selected_amount`; **keeps** `buyer_escrow_token_hash` so `GET chat-messages` still authorizes the same buyer token and returns an empty list (avoids 403 + stale client UI). Optional `purge_notification_subject` / `purge_notification_body` (client-localized; chosen from `seller_locale`) — server forwards as notification email; if either missing, fixed English fallback. |
| LOT-002 | Buyer `POST …/send-message` with `deal_tag` `deal_completed_buyer` (and seller terminal tags) | Pending `sale` rows for that lot → `status=completed`; `App\Support\DealTerminalLotClosure::releaseLotBuyerBinding` clears `buyer_lot_email` so the lot is searchable again | `tests/Unit/DealSaleTransactionCompletionTest.php` | `DealSaleTransactionCompletion` after successful mail + `insertDealChatMessage`; lot release also after `deal_completed_seller_timeout` / `deal_completed_seller`. |
| LOT-003 | Amicable completion tags (`deal_completed_buyer`, `deal_completed_seller_timeout`) | After marking `sale` completed, API best-effort calls Core `complete-reservation-by-buyer` (buyer token) or `complete-reservation` (seller token); Core releases reservation hold and transfers stored service fee to project wallet before cleanup. Token from body/header as for exit/cancel. | — | `ExchangeLotController` (`tryDeleteCoreReservationAfterBuyerAmicableCompletion`, `tryDeleteCoreReservationAfterSellerAmicableCompletion`). Documented in `docs/DEAL_STATE_AND_EXIT_RULES.md`. Add HTTP feature test when a minimal lot + auth harness exists. |
| LOT-012 | Buyer exit/cancel (`buyer_exited`, `buyer-cancel-deal`) | Core `delete-reservation-by-buyer` removes **all** rows for `exchange_lot_id`; API also calls seller `delete-reservation` on seller token Core. | — | `releaseCoreReservationsOnDealEnd`; `xboard-core` `handleDeleteReservationByBuyer`. |
| LOT-004 | `POST …/send-message`, `POST …/send-message-to-buyer`, `POST …/send-payment-details` | Deal `deal_tag` progression matches client `DealChatTagState` (no skipping first-leg proof, no buyer completion before seller payout); `deal_tag` from client cannot override `start_deal` / `buyer_exited` / `seller_exited`. Payment details blocked in terminal chat states. **Resubmit:** buyer `receipt_confirmation` allowed again in `wait_seller_confirm`; seller `seller_transfer_tx` again in `wait_buyer_complete`; duplicate PDF hash allowed in those windows (`ExchangeLotController::validateAndAttachReceiptChecksums`). | `tests/Unit/DealChatTagSequenceValidatorTest.php` | `App\Support\DealChatTagSequenceValidator`; wired in `ExchangeLotController`. |
| LOT-005 | `POST …/send-message` with `message_type=start_deal` | Reject when `exchange_lots.is_active` is false (`error_code=lot_inactive`, HTTP 409), so a seller-disabled listing cannot start a new deal. | — | `ExchangeLotController::sendMessageToSeller`. Add HTTP feature test when escrow-token + lot seed harness exists. |
| LOT-006 | `POST …/send-message` (`start_deal`), `POST …/send-test-notification-email` + optional `client_web_origin` | Web `/open-deal` on `https://{domain}` or `https://dev.{domain}` (any project domain; sandbox API → prod web). Plus API app-landing and `xboard://`. | `tests/Unit/ClientWebOriginTest.php` | `App\Support\ClientWebOrigin`; `docs/WEB_CLIENT_ORIGINS.md`. |
| LOT-007 | `POST …/mark-chat-cleared` | Clear mailbox soft-deletes only non–`deal_tag` rows per side (`deal_tag` kept for stage replay). | `tests/Unit/DealChatMessageVisibilityTest.php` | `App\Support\DealChatMessageVisibility`; `docs/DEAL_CHAT_TABLE_AND_SOFT_DELETE.md`. |
| LOT-008 | `POST …/send-message` with `message_type=start_deal` (new buyer lock) | Hard-delete all `deal_chat_messages` for the lot; Maildir cleanup on seller + buyer deal mailboxes; close open disputes. Same-buyer resume does not wipe. | — | `ExchangeLotController::resetLotDealChatAndMailboxesForNewDeal`; `docs/DEAL_STATE_AND_EXIT_RULES.md`. |
| LOT-009 | `POST …/send-message` with `deal_tag=deal_completed_buyer` after LOT-002 lot release | When `buyer_lot_email` is already cleared but `buyer_escrow_token_hash` still matches the request token (`escrow_token_data` or `X-Escrow-Token`), do **not** return `start_deal_required` (422). Allow buyer completion after seller `deal_completed_seller` with `crypto_payout_tx` even if reducer state is edge-case. Resolve buyer mailbox from header token when body omits wallet. | `tests/Unit/RequestBuyerTokenHashFromRequestTest.php`, `tests/Unit/DealChatTagSequenceValidatorTest.php` | `DealBuyerEscrowTokenHash`; `DealChatTagSequenceValidator::lotAwaitingBuyerCompletionConfirm`. |
| LOT-010 | `POST …/send-payment-details` (seller Core reservation) | `reserved_amount` is in **escrow token** units (`SellerEscrowReservedAmount`), not raw `buyer_selected_amount` (to_currency fiat). E.g. XMR/USD 3.5 USD → reserve ~1 XMR when rate=3.5, not 3.5 XMR. | `tests/Unit/SellerEscrowReservedAmountTest.php` | Mirrors client `sellerReservedAmountForRequirements`; `docs/DEAL_IN_PROGRESS_SCREENS.md` §3. |
| LOT-013 | `POST …/buyer-crypto-payout-to-seller`, `POST …/seller-crypto-payout-to-buyer`, arbiter `deal-payout-to-buyer-from-reservation` | Core `amount` is **escrow token** units (`SellerEscrowReservedAmount::resolve`), same as reservation — not `transactions.amount` (buyer `to_currency`, often fiat). | `tests/Unit/SellerEscrowReservedAmountTest.php` (`test_deal_crypto_payout_uses_same_units_as_reservation_not_sale_tx_fiat`) | Fixes false Core “insufficient balance” when wallet holds reserved XMR but sale row stores USD. |
| LOT-011 | External deal emails / web push `open-deal` URL | **Browser:** web origin `/open-deal`. **App:** `api.{project}/open-deal` with project domain from `client_web_origin`, not node API host. Web push uses web origin. | `tests/Unit/ClientWebOriginTest.php` | `exchange_lots.client_web_origin`; `docs/WEB_CLIENT_ORIGINS.md`. |

## Deal AML

| ID | Document / route | Behavior (short) | Test(s) | Notes |
|----|------------------|------------------|---------|--------|
| AML-001 | `docs/AML_DEAL_PERSISTENCE.md`, `GET /api/v1/internal/aml/outstanding-charges` | AML state only on `transactions.aml_check_json` (pending sale); `AML_CHECK_FAILED` → user-fault `token_charges`; `AML_PROVIDER_UNAVAILABLE` → no charge + operator email (`AML_ADMIN_ALERT_EMAIL` or Core `admin_email`); outstanding = sum of `token_charges` for token hash. | `tests/Unit/DealAmlPersistenceServiceTest.php` | No `deal_aml_*` tables; Core `POST /api/internal/notification-admin-email` for default inbox. |

## General API

| ID | Document / route | Behavior (short) | Test(s) | Notes |
|----|------------------|------------------|---------|--------|
| API-001 | `GET /api/v1/health` | `success`, `data.status` in `ok` \| `degraded` | `tests/Feature/ApiV1HealthTest.php` | |

## Arbiter (`docs/AI_ARBITER_SERVER.md`)

| ID | Document section | Behavior (short) | Test(s) | Notes |
|----|------------------|------------------|---------|--------|
| ARB-001 | Rules-first policy §5 (gray zone) | Sufficient deterministic context → LLM path → `arbiter_status=in_review`, `reason_code=ai_generated` | `tests/Unit/ArbiterServiceStubTest.php` (`test_spec_arb_001_sufficient_context_ai_path`) | Uses `StubArbiterProvider` (no HTTP). |
| ARB-002 | Rules-first policy §2 | `has_replay` true → `manual_review`, `anti_replay_triggered`, no provider | `tests/Unit/ArbiterServiceStubTest.php` (`test_spec_arb_002_anti_replay`) | Controller sets `has_replay` from DB; service contract tested here. |
| ARB-003 | Architecture + container wiring | Laravel resolves `ArbiterService` with `stub` provider under PHPUnit env | `tests/Feature/ArbiterServiceBindingsTest.php` | |
| ARB-004 | Rules-first policy §3 | Confidence ≤ `no_ai_below` → `insufficient_context`, no provider | `tests/Unit/ArbiterServiceStubTest.php` (`test_spec_arb_004_insufficient_context`) | |
| ARB-013 | Rules-first policy §3 (chat thread) | Short `message` **without** server `recent_chat_digest` still can be insufficient; **with** non-empty requester-visible digest: no extra −0.1 for short message **and** +0.12 when fingerprints absent → LLM runs and can stay `in_review` / `ai_generated` (not forced `low_confidence` from 0.45 alone) | `tests/Unit/ArbiterServiceStubTest.php` (`test_spec_arb_013_short_client_message_with_server_chat_digest_still_invokes_llm`) | Matches `POST …/arbiter-message` when client omits evidence hashes but deal chat exists. |
| ARB-005 | Rules-first policy §6 | After LLM, if confidence &lt; manual threshold → `low_confidence`, English escalation suffix | `tests/Unit/ArbiterServiceStubTest.php` (`test_spec_arb_005_low_confidence_escalation`) | |
| ARB-006 | Rules-first policy §7 | Primary LLM unreachable → `provider_unavailable`; operator escalation email when inbox configured; `deal_tag.operator_escalation_email_sent` | `tests/Feature/ArbiterServiceBindingsTest.php` (`test_spec_arb_006_all_providers_fail`, `test_spec_arb_006_all_providers_fail_operator_mail_when_inbox_configured`) | Test method names are historical; behavior is **single provider** only. First test clears `arbiter_operator_mail.escalation_email` so global `.env` in CI does not flip the flag. |
| ARB-012 | Rules-first policy §4 + Receipt PDF § | Image-only receipt path sends `ArbiterOperatorEscalationMail` when `ARBITER_OPERATOR_ESCALATION_EMAIL` is set; copy in `resources/views/mail/arbiter_operator_escalation.blade.php`; TTL `ARBITER_OPERATOR_ACTION_LINK_TTL_DAYS` | `tests/Feature/ArbiterOperatorEscalationMailTest.php`, `tests/Unit/ArbiterDealContextPlainTextTest.php` | `ArbiterDealContextPlainText` aligns LLM/operator bodies. |
| ARB-007 | Testing checklist §7 + `deal_disputes` | `GET dispute-status` still sees `in_review` row after arbiter | — | **Manual / integration** (needs lot + dispute + HTTP). Add `Feature` test when harness exists. |
| ARB-008 | Security model | `provider_hint` not used for routing | — | **Manual** (curl checklist §2) or future HTTP feature test. |
| ARB-009 | `resources/prompts/arbiter_exchange_rules.md` + `ArbiterPromptContextAssembler` | Arbiter LLM user prompt includes rules digest, `deal_machine_state`, and requester-visible `recent_chat_digest` | `tests/Feature/ArbiterExchangeRulesFileTest.php` | Does not assert provider output; file presence + `loadRulesDigest()` smoke. `docs/ARBITER_EXCHANGE_RULES.md` is a pointer stub only. |
| ARB-011 | `docs/AI_ARBITER_SERVER.md` — *Per-lot AI invocation budget* | Before `ArbiterService`, `POST …/arbiter-message` atomically increments `exchange_lots.arbiter_ai_invocations` while below cap; else **422** `ai_arbiter_iteration_limit_reached`. `GET …/dispute-status` exposes `arbiter_ai.*`. | — | Add `Feature` test when lot + escrow harness exists; until then manual checklist §8 in `AI_ARBITER_SERVER.md`. |
| ARB-010 | Receipt PDF § | `ReceiptAttachmentStorage::absolutePathIfExists` resolves a stored `email_attachments/.../*.pdf` path under the configured disk / `PAYMENT_VERIFICATION_ATTACHMENTS_ROOT` | `tests/Unit/ReceiptAttachmentStoragePathTest.php`, `tests/Unit/ReceiptPdfTextExtractorTest.php` | `ArbiterReceiptPdfResolver` uses `ExchangeLot::latestReceiptTransaction()`. Text excerpt for all providers via `ReceiptPdfTextExtractor` + `smalot/pdfparser`. See `docs/AI_ARBITER_SERVER.md`. |

## Workflow for contributors

1. Describe behavior in the relevant `docs/*.md` file.
2. Add a row to this table with a new ID (next free in that area).
3. Add or update a PHPUnit test; tag with `@group spec` and `@group spec-<ID>`.
4. For behavior that needs full HTTP + DB, keep the test row empty in the **Test(s)** column until implemented, or reference a manual checklist step in **Notes**.
