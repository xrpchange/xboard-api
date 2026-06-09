# AI dispute arbiter (server-side)

**Scope:** `xboard-api` only. LLM API keys stay in `.env` (BYOK). Clients must not select or impersonate the provider.

### Specification vs implementation

Documented behavior is tracked in **[`docs/SPEC_REGISTRY.md`](SPEC_REGISTRY.md)** with stable IDs (`ARB-001`, …) linked to PHPUnit tests. After changing this file or arbiter code, update the registry and run:

```bash
php artisan test --group spec
```

---

## Security model

- **Per-lot AI invocation budget** — see the dedicated section below (atomic reservation **before** any LLM work; separate from counting chat rows).
- **Single LLM provider:** only `ARBITER_PRIMARY_PROVIDER` (default **`gigachat`**) is invoked per request — **no automatic failover** to other vendors. Deprecated env `ARBITER_FALLBACK_PROVIDERS` is ignored.  
- Request fields `provider` / `provider_hint` are **ignored for routing**; they may be logged and passed into the prompt as *non-authoritative* context (`provider_hint_applied` is always `false` in API responses).  
- **No API keys** are returned to clients.  
- Arbiter chat lines are **fixed English** technical/system copy where the server generates text (per project rules: no localized strings on the server).

---

## Per-lot AI invocation budget (`arbiter_ai_invocations`)

**Why:** Counting only `deal_chat_messages` rows with `sender_side = arbiter_ai` does **not** cap spend. `ExchangeLotController::requestArbiterMessage` calls `ArbiterService::buildArbiterMessage()` (which may invoke paid LLM APIs) **before** inserting a chat row. A client could otherwise repeat requests that fail after the LLM returns but before insert, or exploit races, and burn budget without increasing the visible message count.

**What:** Column **`exchange_lots.arbiter_ai_invocations`** (unsigned int, default `0`). Migration: `database/migrations/2026_04_19_000001_add_arbiter_ai_invocations_to_exchange_lots.php` (on MySQL, backfills from existing `arbiter_ai` message counts per lot).

**When:** After buyer/seller escrow auth, validation, and **participant policy** resolve to the **AI** path (not `operator_escalation`), **before** `ArbiterService::buildArbiterMessage()`, the controller runs a single atomic SQL increment:

```sql
UPDATE exchange_lots
SET arbiter_ai_invocations = arbiter_ai_invocations + 1
WHERE id = ? AND arbiter_ai_invocations < ?
```

- If **no row** is updated (`affected === 0`), the handler returns **HTTP 422** and **does not** call `ArbiterService` (no LLM).
- **`0` cap:** `config('deal.max_ai_arbiter_invocations_per_lot')` — if set to **`0`**, the increment block is skipped (unlimited invocations; not recommended in production).

**Limit response (JSON, fixed English):**

| Field | Meaning |
|--------|---------|
| `success` | `false` |
| `message` | e.g. `AI arbiter invocation limit reached for this deal.` |
| `error_code` | `ai_arbiter_iteration_limit_reached` |
| `max_ai_arbiter_invocations` | Effective cap (same as config when cap &gt; 0) |

**Semantics:** One successful `UPDATE … + 1` consumes a slot **even if** a later step throws (e.g. insert failure). That trade-off favours **budget protection** over “refund the slot” on rare server errors.

**Out of scope for this counter:** Human **operator** replies (`DealChatMessage` with `sender_side = exchange_operator`, tag `operator_mail_reply`) are written from `ArbiterOperatorActionController` and **never** hit `requestArbiterMessage` — **no** per-lot API cap on operator volume. Participant **operator escalation** (`operator_escalation_requested` chat row + operator email, no LLM) also **does not** increment `arbiter_ai_invocations`.

---

## Participant arbiter button policy

**Class:** `App\Support\DisputeArbiterParticipantPolicy`.

Each side (buyer/seller) in an active dispute cycle:

1. Must post **at least one chat message** as that side before the arbiter button works (`422` + `dispute_participant_comment_required`).
2. **First** press in the cycle → **AI** (`arbiter_message`, consumes invocation budget when cap enabled).
3. **Second** press (after their AI reply, before operator answers in chat) → **no LLM**; `ArbiterOperatorEscalationNotifier::notifyPolicyManualReview('participant_requested_review', …)` and a **system** chat row with `deal_tag.t = operator_escalation_requested` (`requested_by`, `operator_escalation_email_sent`). Operator email subject includes **Buyer** or **Seller** (who pressed).
4. Further presses until `operator_mail_reply` → `422` + `arbiter_wait_for_operator_reply`.
5. Cycle resets after the latest `operator_mail_reply` (or from `deal_disputes.opened_at` if none).

Tag registry: **`docs/DEAL_EMAIL_SUBJECT_TAG.md`** (*Dispute / arbiter chat tags*).

**`GET /api/v1/exchange-lots/{lot}/dispute-status`:** Besides `dispute`, the JSON includes:

```json
"arbiter_ai": {
  "invocations": 12,
  "max_invocations": 30,
  "limit_reached": false
}
```

- `max_invocations` is `null` when the cap is disabled (`0`).
- `limit_reached` is `invocations >= max_invocations` when `max_invocations` is non-null.

Clients may use this to disable **Ask AI arbiter** before the user taps (optional UX).

---

## Architecture

| Piece | Role |
|--------|------|
| `App\Services\Arbiter\Contracts\ArbiterProvider` | Provider interface (`providerId()`, `generate()`). |
| `App\Services\Arbiter\GigaChatArbiterProvider` | **Production LLM:** Sber GigaChat — OAuth token (cached ~30 min) + OpenAI-compatible `POST /chat/completions` under `api/v1`. |
| `App\Services\Arbiter\StubArbiterProvider` | PHPUnit / local: used when `ARBITER_PRIMARY_PROVIDER=stub`. |
| `App\Services\Arbiter\DeepSeekArbiterProvider` | *(DI only)* Not called unless `ARBITER_PRIMARY_PROVIDER=deepseek`. No failover from GigaChat. |
| `App\Services\Arbiter\AnthropicArbiterProvider` | *(DI only)* Same — single-provider policy. |
| `App\Services\Arbiter\OpenAiArbiterProvider` | *(DI only)* Same. |
| `App\Support\ArbiterReceiptPdfResolver` | Resolves a stored receipt PDF path for the lot whose `sha256` matches the client `pdf_sha256` (via `transactions.receipt_links_json` + `ReceiptAttachmentStorage`). |
| `App\Support\ArbiterDealContextPlainText` | Shared plain-text block (dispute fields, digests, receipt excerpt) for LLM `user_prompt` and operator escalation email. |
| `App\Services\Arbiter\ArbiterOperatorEscalationNotifier` | Sends `ArbiterOperatorEscalationMail` when receipt PDF is image-only (no extractable text). |
| `App\Services\Arbiter\ArbiterService` | Rules-first orchestration; calls **only** the configured primary provider id. |
| `App\Support\ArbiterPromptContextAssembler` | Loads `resources/prompts/arbiter_exchange_rules.md`, `DealChatTagSequenceValidator::computeStateForLot`, and a requester-visible chat digest for the LLM user prompt. |
| `resources/prompts/arbiter_exchange_rules.md` | Canonical English digest for the model (not end-user UI copy). |
| `App\Providers\AppServiceProvider` | Wires providers and thresholds from config. |
| `ExchangeLotController::requestArbiterMessage` | Authz; **atomic `arbiter_ai_invocations` reservation** when cap &gt; 0; validation; anti-replay; `ArbiterService`; dispute row update; chat insert. |

---

## LLM context (exchange rules + chat)

Before calling the provider, the controller attaches (non-authoritative) context:

- **`exchange_rules_digest`** — full contents of **`resources/prompts/arbiter_exchange_rules.md`** (fallback English line if file missing; hard safety cap in code).  
- **`deal_machine_state`** — `DealChatTagSequenceValidator::computeStateForLot($lot)` (same reducer as `deal_tag` validation).  
- **`recent_chat_digest`** — last N messages visible to the **requester** (same `deleted_by_*` filters as `GET …/chat-messages`), one line per message with truncated `body` / `deal_tag` (format: `id=N side=… tag={…} body=…`).
- **`declared_transactions_digest`** — chat rows whose `deal_tag.t` is `buyer_crypto_tx`, `receipt_confirmation` (buyer bank receipt PDFs), `seller_transfer_tx`, or `deal_completed_seller` (`crypto_payout_tx`), including stored **`core_tx_check`** when the message was accepted. Built by `App\Support\DealArbitrationTxProofCatalog`. Same block is appended in operator escalation mail (both image-only and policy/manual paths, including `provider_unavailable`) and in `ArbiterDealContextPlainText`.
- **`deal_milestones_digest`** — chronological key `deal_tag` milestones (`payment_details`, `receipt_confirmation`, `seller_received_funds`, `buyer_destination_details`, dispute tags, …) via `DealArbitrationMilestonesDigest`. **`deal_machine_state_meaning`** explains the state code (e.g. `wait_seller_transfer` = seller already confirmed fiat; seller must send crypto).
- **`recent_chat_digest`** for the LLM **omits** prior `arbiter_message` / `operator_mail_reply` rows (buyer/seller thread only). Operator escalation mail still includes the full requester-visible thread.
- When the client omits `pdf_sha256`, the server auto-fills it from the lot’s latest receipt (`ArbiterReceiptPdfResolver::resolveLatestKnownReceiptSha256Hex`) so PDF text can reach the model.
- When the primary LLM returns verdict **`manual_review`**, operator escalation mail is sent (`reason_code=ai_inconclusive`) in addition to the AI chat post — not only on provider failure.

Enforcement of progression and payouts remains **server-side only**; the digest steers the model toward questions and conservative verdicts.

### Operator mail: declared transfer checks (arbitration)

When `ARBITER_OPERATOR_ESCALATION_EMAIL` is set, `ArbiterOperatorEscalationMail` adds a **Declared transfers (arbitration)** section:

| Control | Purpose |
|---------|---------|
| **Check transfer (Core)** | Green button — signed GET `/arbiter/operator/{lot}/verify-tx/{message_id}`. One page: pass/fail verdict, prior check at send time, optional explorer link. Uses **sandbox** or **production** Core URL from the API host (`sandbox.api.*` → `CORE_URL`; `api.*` → `CORE_PRODUCTION_URL`). |
| **Block explorer** | Blue button — `config/xboard.php` → `tx_explorer_url_templates.production` or `.sandbox` (`{tx}` by Core `crypto_type`). Sandbox deals open testnet explorers (e.g. Blockstream testnet, Nile Tronscan, Amoy Polygonscan). |
| **Open receipt** | Bank-only proofs: buyer `receipt_confirmation` or seller `seller_transfer_tx` with `receipt_links` and no on-chain id — direct HTTPS links to PDFs. |

Mail shows an environment pill (**Production** / **Sandbox**) and **Verified when sent** status from `deal_tag.core_tx_check`. Operator does not copy tx ids — clicks buttons in the email only.

Buyer fiat leg (`receipt_confirmation`) and bank-only second leg (`seller_transfer_tx` with `receipt_links` and no `tx_number`) are listed as **bank receipt proof** — receipt buttons only.

`Compensate buyer` / `Deny buyer` links remain acknowledgment-only (no payout automation); operator uses explorer/Core results plus full chat digest to decide.

### Receipt PDF (text for all; binary only when text exists)

When the client sends **`pdf_sha256`** (64 hex), the server uses the **current deal transaction** for receipts — the same row as when saving bank receipts: **`ExchangeLot::latestReceiptTransaction()`** (latest `transactions.id` for the lot, filtered by `buyer_lot_email` when it is set on the lot). It scans **`receipt_links_json`** on that row only (`ReceiptAttachmentStorage::linkToRelativePath` → on-disk path). If a file exists and **`hash_file('sha256', …)`** matches:

1. The server extracts plain text with **`smalot/pdfparser`** (truncated to **`ARBITER_RECEIPT_PDF_TEXT_EXCERPT_MAX_CHARS`**, default 8000 UTF-8 characters).
2. **If extraction is non-empty:** that text is included as **`receipt_pdf_text_excerpt`** in the text user prompt for **every** provider. **Anthropic** and **OpenAI** additionally receive the PDF as a **separate binary** (Anthropic `document` base64; OpenAI **`POST /v1/responses`** `input_file` + `input_text`, vision-capable models). **GigaChat / DeepSeek** see the receipt via the text excerpt only.
3. **If extraction is empty** (scanned image / bitmap-only PDF — not useful as text): the **LLM is not called**. The server sends an **internal operator email** (HTML + plain text) to **`ARBITER_OPERATOR_ESCALATION_EMAIL`** (if set). Inbox and signed-link TTL come from **`config/arbiter_operator_mail.php`** (`.env`). **All copy** (subject, preamble, English button labels **Reply** / **Compensate buyer** / **Deny buyer**) lives in a **single Blade template**: **`resources/views/mail/arbiter_operator_escalation.blade.php`** (`mailSection`: `subject` \| `html` \| `plain`). The body uses the same structured deal context as the LLM would receive (`App\Support\ArbiterDealContextPlainText`). The mail includes **three signed GET links** (`/arbiter/operator/{lot}/{action}`) scoped to that lot and expiry (`ARBITER_OPERATOR_ACTION_LINK_TTL_DAYS`, default **7**). `reason_code`: `receipt_pdf_image_only_operator_notified`. If the inbox env var is empty, only a warning is logged.

Max binary read size: **`ARBITER_RECEIPT_PDF_MAX_BYTES`** (default 5 MiB in `config/services.php`).

---

## LLM response format (machine verdict line)

The model must return **plain text** suitable for dispute chat, then **one final line** with no leading spaces:

- **Prefix:** exactly `XBOARD_ARBITER_JSON:` (see `App\Services\Arbiter\ArbiterResponseParser::LINE_PREFIX`).  
- **Payload:** immediately after the prefix, a **single JSON object** on the same line, e.g. `{"verdict":"manual_review"}`.  
- **`verdict`** must be one of: `manual_review`, `favor_buyer`, `favor_seller`, `continue_review` (`ArbiterResponseParser::ALLOWED_VERDICTS`). Unknown values are treated as `continue_review`.

The server splits **display text** (everything before that last non-empty line) from **verdict** (parsed JSON). If the prefix line is missing, the whole reply is treated as display text and verdict defaults to `continue_review`.

---

## Rules-first policy

1. **Deterministic context score** from message length, presence of evidence hashes, DKIM hash, replay flag, and whether the server assembled a **non-empty** `recent_chat_digest` (requester-visible chat). A very short client `message` does **not** apply the extra down-score when that digest exists; if fingerprints are absent but the digest exists, a small **+0.12** bump avoids treating “chat-only” disputes as below the post-LLM manual threshold solely due to missing hashes.  
2. **Anti-replay:** if any of `pdf_sha256` / `email_message_sha256` / `dkim_signature_sha256` matches a previous arbiter message’s `deal_tag.anti_replay.*` for the same lot → **manual review**, no LLM call (`reason_code`: `anti_replay_triggered`).  
3. **No-AI band:** if context score **≤ `ARBITER_NO_AI_BELOW`** → **manual review**, no LLM (`reason_code`: `insufficient_context`).  
4. **Image-only receipt PDF:** if `pdf_sha256` matches a file on disk but **text extraction yields empty** → **no LLM**; operator email with full text context and signed action links when `ARBITER_OPERATOR_ESCALATION_EMAIL` is set (`reason_code`: `receipt_pdf_image_only_operator_notified`).  
5. **Gray zone:** otherwise the server calls **only** the configured primary provider (`ARBITER_PRIMARY_PROVIDER`, default `gigachat`). On transport/API failure there is **no** switch to another LLM — the request ends in `provider_unavailable` and operator escalation (see step 7).  
6. **Post-LLM escalation:** if context score **&lt; `ARBITER_MANUAL_REVIEW_THRESHOLD`** but LLM ran, the response body includes the model text plus an English escalation suffix; `arbiter_status` is `manual_review` (`reason_code`: `low_confidence`).  
7. **Primary provider fails:** fixed English manual message (`reason_code`: `provider_unavailable`). **`ArbiterOperatorEscalationNotifier::notifyPolicyManualReview('provider_unavailable', …)`** runs immediately (same path as other policy escalations): if **`ARBITER_OPERATOR_ESCALATION_EMAIL`** / `config('arbiter_operator_mail.escalation_email')` is set, the operator receives **`ArbiterOperatorEscalationMail`** with full deal context and signed `/arbiter/operator/{lot}/…` links — this is the intended **hand-off off the LLM budget**. The chat row’s `deal_tag` includes **`operator_escalation_email_sent`: true|false** so clients can show accurate copy. If the inbox is not configured, only a warning is logged and the flag is **false** (operators must be reached by other ops channels).
8. **Bank receipt duplicate guard:** receipt-file checksums (`sha256`) are persisted in `receipt_file_checksums`; duplicate submission (`sender_side` + `tag_type` + checksum within same lot) is rejected (`error_code`: `duplicate_receipt_checksum`). Retention is aligned to deal chat TTL via `receipt-checksums:prune` (default 15 days).

### After operator reply: restart full review cycle

When an exchange operator replies in the dispute thread, subsequent checks are treated as a **fresh review pass**:

- do **not** assume prior AI/operator conclusions are final for new evidence;
- re-check payment proof and bank receipt context from scratch;
- re-check crypto-side facts (tx refs / payout state) from scratch;
- keep the same deterministic anti-replay and confidence gates for each new `POST …/arbiter-message`.

In short: operator participation does not enable a "shortcut mode" - each new review round follows the normal rules-first pipeline again.

---

## Single provider (no failover)

`ArbiterService::resolveProviderOrder()` returns **only** `[ARBITER_PRIMARY_PROVIDER]`. There is **no** second LLM attempt on error.  
`config('services.arbiter.fallback_providers')` is always **`[]`**; **`ARBITER_FALLBACK_PROVIDERS`** in `.env` is **ignored** (deprecated).

**Production default:** `ARBITER_PRIMARY_PROVIDER=gigachat` + `ARBITER_GIGACHAT_AUTHORIZATION_KEY`.

```env
ARBITER_PRIMARY_PROVIDER=gigachat
ARBITER_GIGACHAT_AUTHORIZATION_KEY=<from Sber studio>
```

PHPUnit uses `ARBITER_PRIMARY_PROVIDER=stub` (see `phpunit.xml`).

---

## GigaChat TLS: Russian Trusted CA (production Linux)

Sber **GigaChat** OAuth and API use TLS certificates issued under the **Russian Trusted** chain (Ministry of Digital Development). Default Ubuntu/Debian **`ca-certificates`** bundles (Mozilla) **do not** include that root. Outbound `curl` / Laravel `Http` then fail with:

```text
cURL error 60: SSL certificate problem: self-signed certificate in certificate chain
… for https://ngw.devices.sberbank.ru:9443/api/v2/oauth
```

Laravel logs: `[arbiter] provider_failed` → `primary_provider_failed` → chat `reason_code=provider_unavailable` (operator escalation email when `ARBITER_OPERATOR_ESCALATION_EMAIL` is set).

**Certbot / Let’s Encrypt** on the same host only covers **inbound** HTTPS for your vhosts (`api.*`, `mail.*`, etc.). It does **not** add trust for **outbound** calls to `*.devices.sberbank.ru`. Fix the **system trust store**, not Certbot.

### Certificate chain (typical)

| Depth | CN |
|-------|-----|
| Root | Russian Trusted Root CA |
| Intermediate | Russian Trusted Sub CA |
| Leaf | `ngw.devices.sberbank.ru` / `gigachat.devices.sberbank.ru` |

### Install on Debian/Ubuntu (recommended)

Run on the **API** server as root (paths survive reboots; `update-ca-certificates` merges into `/etc/ssl/certs/`):

```bash
sudo mkdir -p /usr/local/share/ca-certificates/russian-trusted
cd /tmp
curl -fsSL -o russian_trusted_root_ca.cer \
  https://gu-st.ru/content/Other/doc/russian_trusted_root_ca.cer
curl -fsSL -o russian_trusted_sub_ca.cer \
  https://gu-st.ru/content/Other/doc/russian_trusted_sub_ca.cer

# DER → PEM if needed (gu-st.ru serves DER)
sudo openssl x509 -inform der -in russian_trusted_root_ca.cer \
  -out /usr/local/share/ca-certificates/russian-trusted/russian_trusted_root_ca.crt
sudo openssl x509 -inform der -in russian_trusted_sub_ca.cer \
  -out /usr/local/share/ca-certificates/russian-trusted/russian_trusted_sub_ca.crt

sudo update-ca-certificates
```

Official distribution page (Russian): [Госуслуги — корневые и промежуточные сертификаты](https://www.gosuslugi.ru/crt). GOST 2021 variants on `gu-st.ru` may 404; the two files above are enough for the current Sber leaf chain (verified 2026-06).

### Verify after install

```bash
# TLS must succeed (HTTP 405 on GET without POST body is OK)
curl -sS -o /dev/null -w "oauth ssl_verify=%{ssl_verify_result} http=%{http_code}\n" \
  --max-time 15 https://ngw.devices.sberbank.ru:9443/api/v2/oauth

# 401 without Bearer is OK — means TLS passed
curl -sS -o /dev/null -w "api ssl_verify=%{ssl_verify_result} http=%{http_code}\n" \
  --max-time 15 https://gigachat.devices.sberbank.ru/api/v1/models
```

`ssl_verify=0` = success. From `/var/www/xboard-api`, OAuth via Laravel with production config should return HTTP **200** and a non-empty `access_token` while `ARBITER_GIGACHAT_VERIFY_SSL` stays **enabled** (default when `APP_ENV=production`).

**Laravel check (preferred):**

```bash
cd /var/www/xboard-api
sudo -u www-data php artisan arbiter:gigachat-check --clear-cache
```

Expect `oauth_http=200`, `models_http=200`, a non-empty `models_sample=…` line, `chat_http=200`, `chat_preview=OK`. If chat returns **401** after OAuth succeeded, clear the cached token (`--clear-cache`) — `GigaChatArbiterProvider` also retries once on 401 with a fresh token.

### GigaChat model selection

Only one model is used per deployment: **`ARBITER_GIGACHAT_MODEL`** (default `GigaChat`). The arbiter does **not** try other model ids on failure.

1. Run `arbiter:gigachat-check` and read **`models_sample`** — ids your authorization key can call (e.g. `GigaChat`, `GigaChat-2`, `GigaChat-2-Pro`, `GigaChat-Pro`).
2. Set the model in **`.env`** on the API host, for example:

```env
ARBITER_GIGACHAT_MODEL=GigaChat-2
```

3. Reload Laravel config (production):

```bash
cd /var/www/xboard-api
sudo -u www-data php artisan config:cache
```

4. Re-run the check (optional `--clear-cache`):

```bash
sudo -u www-data php artisan arbiter:gigachat-check --clear-cache
```

If the configured model is missing from `/models`, the check prints a **warning**; chat completion will fail until you pick a listed id.

### When *not* to disable verify

Prefer **installing the CA** over `ARBITER_GIGACHAT_VERIFY_SSL=false`. Use `false` only as a temporary workaround on hosts where you cannot update the trust store (e.g. some local XAMPP setups). Production (`server@xrpchange.site`, `/var/www/xboard-api`) was fixed by adding Russian Trusted CAs (2026-06); **`verify_ssl` left enabled**.

---

## Environment variables

| Variable | Default | Description |
|----------|---------|-------------|
| `ARBITER_PRIMARY_PROVIDER` | `gigachat` | **Only** LLM provider id invoked for arbiter (`gigachat` or `stub` in tests). |
| `ARBITER_FALLBACK_PROVIDERS` | *(ignored)* | **Deprecated.** Arbiter never switches providers; leave unset. |
| `ARBITER_NO_AI_BELOW` | `0.35` | Skip LLM when deterministic score is **≤** this value. |
| `ARBITER_MANUAL_REVIEW_THRESHOLD` | `0.55` | After LLM, mark `arbiter_status=manual_review` when score is below this. |
| `ARBITER_RECEIPT_PDF_MAX_BYTES` | `5242880` | Max receipt PDF size read from disk for hashing, text extraction, and binary attachment. |
| `ARBITER_RECEIPT_PDF_TEXT_EXCERPT_MAX_CHARS` | `8000` | Max UTF-8 characters of extracted PDF text injected into the text prompt for **every** provider. |
| `ARBITER_OPERATOR_ESCALATION_EMAIL` | *(empty)* | When a receipt PDF matches on disk but has **no extractable text**, send internal context to this address (no LLM). Wired through `config/arbiter_operator_mail.php`. Example in `.env.example`: `tehnograny@yandex.ru`. |
| `ARBITER_OPERATOR_ACTION_LINK_TTL_DAYS` | `7` | Expiry (days) for signed operator action URLs in that email. Set explicitly in `.env` / `.env.example` alongside the operator inbox. |
| `ARBITER_RESOLVE_ON_FAVOR_BUYER` | `true` | When LLM `verdict` is `favor_buyer` and `arbiter_status` is `in_review`, close `deal_disputes` so seller can use normal crypto payout (unless auto Core payout is on and fails). |
| `ARBITER_RESOLVE_ON_FAVOR_SELLER` | `true` | Same for `favor_seller`. |
| `ARBITER_FORCE_CLOSE_BUYER_DISPUTE_ON_FAVOR_SELLER` | `true` | If enabled, AI verdict `favor_seller` closes buyer-opened disputes even when generic `ARBITER_RESOLVE_ON_FAVOR_SELLER` is disabled. |
| `ARBITER_AUTO_CORE_PAYOUT_ON_FAVOR_BUYER` | `false` | When `true`, after `favor_buyer` Laravel calls Core `POST /api/deal-payout-to-buyer-from-reservation` (seller token loaded from `reservations` / `test_reservations`; same `X-Core-Key` as other API-only Core calls). |
| `ARBITER_GIGACHAT_AUTHORIZATION_KEY` | *(empty)* | Sber **authorization key** (see studio quickstart): paste Base64 value only, or full `Basic …` / `Bearer …` header value. |
| `ARBITER_GIGACHAT_SCOPE` | `GIGACHAT_API_PERS` | OAuth scope (`GIGACHAT_API_B2B` / `GIGACHAT_API_CORP` for legal entities per Sber docs). |
| `ARBITER_GIGACHAT_OAUTH_URL` | `https://ngw.devices.sberbank.ru:9443/api/v2/oauth` | Token endpoint. |
| `ARBITER_GIGACHAT_API_BASE_URL` | `https://gigachat.devices.sberbank.ru/api/v1` | OpenAI-compatible API root. |
| `ARBITER_GIGACHAT_MODEL` | `GigaChat` | Model id for chat completions (see *GigaChat model selection*; list via `arbiter:gigachat-check` → `models_sample`). |
| `ARBITER_GIGACHAT_TIMEOUT` | `45` | HTTP timeout (seconds) for OAuth + chat. |
| `ARBITER_GIGACHAT_VERIFY_SSL` | *(env-dependent)* | If **unset**: verify TLS only when `APP_ENV=production`; otherwise `false` (many dev hosts / XAMPP lack a good CA bundle for Sber). Set explicitly `true` or `false` to override. |
| `ARBITER_DEEPSEEK_API_KEY` | *(empty)* | DeepSeek API key. |
| `ARBITER_DEEPSEEK_BASE_URL` | `https://api.deepseek.com` | API base (no trailing slash); override only for compatible proxies. |
| `ARBITER_DEEPSEEK_MODEL` | `deepseek-chat` | Model id (see DeepSeek docs). |
| `ARBITER_DEEPSEEK_TIMEOUT` | `30` | HTTP timeout (seconds). |
| `ARBITER_ANTHROPIC_API_KEY` | *(empty)* | Anthropic API key. |
| `ARBITER_ANTHROPIC_MODEL` | `claude-3-7-sonnet-latest` | Model id. |
| `ARBITER_ANTHROPIC_TIMEOUT` | `20` | HTTP timeout (seconds). |
| `ARBITER_OPENAI_API_KEY` | *(empty)* | OpenAI API key (only used when `ARBITER_PRIMARY_PROVIDER=openai`). |
| `ARBITER_OPENAI_MODEL` | `gpt-4o-mini` | Model id. |
| `ARBITER_OPENAI_TIMEOUT` | `20` | HTTP timeout (seconds). |
| `DEAL_MAX_AI_ARBITER_INVOCATIONS_PER_LOT` | `30` | Max successful **reservations** per lot for `POST …/arbiter-message` (see *Per-lot AI invocation budget*). Primary env key. |
| `DEAL_MAX_AI_ARBITER_MESSAGES_PER_LOT` | *(fallback)* | Legacy alias: used only if `DEAL_MAX_AI_ARBITER_INVOCATIONS_PER_LOT` is unset. Same numeric meaning as today’s invocation cap. |

**Config file:** `config/deal.php` → `max_ai_arbiter_invocations_per_lot` (reads the env keys above; `0` = disable cap).

Copy from `.env.example` and set keys only on the server.

**GigaChat OAuth token** is cached in the app cache store (`Cache::put`, key derived from a hash of the authorization key). Use **Redis** or another shared cache in multi-PHP-worker deployments so workers reuse the same access token.

---

## Machine verdict + optional Core payout

Successful LLM replies must end with a line `XBOARD_ARBITER_JSON:{"verdict":"..."}` (see `ArbiterResponseParser`). Verdicts: `manual_review`, `favor_buyer`, `favor_seller`, `continue_review`. Chat body shown to users omits that line.

## `deal_disputes` vs `arbiter_status`

- After an arbiter message, **`deal_disputes.status`** is usually **`in_review`** until the dispute is closed (e.g. deal completed, exit, or arbiter **`favor_buyer` / `favor_seller`** resolution per env above).  
- Semantic outcome is in the JSON response and in `deal_chat_messages.deal_tag`: **`arbiter_status`** = `in_review` | `manual_review`, plus **`arbiter_verdict`** when parsed.

---

## Request body (optional fields)

All optional. Hashes must be 64 hex chars when present.

| Field | Purpose |
|--------|---------|
| `message` | Free-text context from buyer/seller. |
| `provider` / `provider_hint` | **Not used for provider selection**; logged / prompt context only. |
| `bank_timezone` | PHP timezone id, e.g. `Europe/Moscow`, used with `payment_time_raw`. |
| `payment_time_raw` | Time string parseable by `DateTimeImmutable` in `bank_timezone`. |
| `pdf_sha256` | Evidence fingerprint. |
| `email_message_sha256` | Evidence fingerprint. |
| `dkim_signature_sha256` | Evidence fingerprint. |

Server computes `bank_time.normalized_utc` when both timezone and raw time are valid.

---

## Testing checklist (curl + expectations + logs)

**Prerequisites:** valid escrow token for seller or buyer on lot `{id}`; `X-Escrow-Token` header as in other protected routes.

### 1) Happy path (LLM + in_review)

- **Setup:** `ARBITER_PRIMARY_PROVIDER=gigachat`, `ARBITER_GIGACHAT_AUTHORIZATION_KEY` set; thresholds default; message length ≥ 40; include one evidence hash.  
- **Request:**

```bash
curl -sS -X POST "https://sandbox.api.xdexboard.com/api/v1/exchange-lots/{id}/arbiter-message" \
  -H "Content-Type: application/json" \
  -H "X-Escrow-Token: YOUR_TOKEN" \
  -d "{\"message\":\"Buyer states payment was sent with reference ABC123 and requests seller confirmation.\",\"pdf_sha256\":\"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\",\"bank_timezone\":\"UTC\",\"payment_time_raw\":\"2026-03-27 12:00:00\"}"
```

- **Expect:** HTTP 200, `success: true`, `data.dispute_status` = `in_review`, `data.arbiter_status` = `in_review`, `data.provider_used` = `gigachat`, `data.body` = model text (English).  
- **Logs:** `[arbiter] policy_applied` with `arbiter_status=in_review`, matching `provider_used`.

### 2) Provider hint ignored

- **Request:** same as (1) but add `"provider":"openai"` or `"provider_hint":"fake"`.  
- **Expect:** `provider_used` matches server primary (`gigachat` by default) when the call succeeds; `provider_hint_applied` = `false`.  
- **Logs:** `provider_hint` present in log line, `provider_used` unchanged.

### 3) No-AI path (insufficient context)

- **Setup:** very short `message` and no hashes so score &lt; `ARBITER_NO_AI_BELOW`.  
- **Request:**

```bash
curl -sS -X POST "https://sandbox.api.xdexboard.com/api/v1/exchange-lots/{id}/arbiter-message" \
  -H "Content-Type: application/json" \
  -H "X-Escrow-Token: YOUR_TOKEN" \
  -d "{\"message\":\"short\"}"
```

- **Expect:** HTTP 200, `data.arbiter_status` = `manual_review`, `reason_code` = `insufficient_context`, `provider_used` = null, fixed English body.  
- **Logs:** `policy_applied` with `arbiter_status=manual_review`, `reason_code=insufficient_context`.

### 4) Anti-replay

- **Steps:** Run (1) with a fixed `email_message_sha256` (64 hex). Repeat the same hash on a second request.  
- **Expect:** second response `arbiter_status` = `manual_review`, `reason_code` = `anti_replay_triggered`, `deal_tag.anti_replay.triggered` = true on the new message.  
- **Logs:** `has_replay=true`.

### 5) Primary LLM unreachable

- **Setup:** any failure of the sole primary provider while `ARBITER_PRIMARY_PROVIDER=gigachat` (no other LLM is tried). Common causes:
  - invalid or empty `ARBITER_GIGACHAT_AUTHORIZATION_KEY`;
  - **TLS:** `cURL error 60` to `ngw.devices.sberbank.ru` — missing **Russian Trusted** CAs on the host (see *GigaChat TLS: Russian Trusted CA* above);
  - OAuth/API timeout or empty model response.
- **Expect:** HTTP 200, `manual_review`, `reason_code` = `provider_unavailable`. Chat `deal_tag` includes **`operator_escalation_email_sent`** (`true` when escalation mail actually sent, else `false`).  
- **Operator mail:** With `ARBITER_OPERATOR_ESCALATION_EMAIL` set, **`Mail::assertSent(ArbiterOperatorEscalationMail::class)`** in a feature test should see `policyReasonCode = provider_unavailable`.  
- **Logs:** `[arbiter] provider_failed` (primary, includes `error` message), then `[arbiter] primary_provider_failed`.

### 6) `dispute-status` still returns row

- After any successful arbiter call, `GET /api/v1/exchange-lots/{id}/dispute-status` should still return an active dispute with `status: in_review` (not `manual_review` at DB level).

### 7) Invocation cap (`422`)

- **Setup:** cap enabled (default). Pick a test lot id.  
- **Loop:** Call `POST …/arbiter-message` with valid escrow auth until `arbiter_ai_invocations` reaches `max_invocations` (or temporarily set `DEAL_MAX_AI_ARBITER_INVOCATIONS_PER_LOT=1` on a dev host).  
- **Expect:** Next call returns **HTTP 422**, `success: false`, `error_code` = `ai_arbiter_iteration_limit_reached`, `max_ai_arbiter_invocations` in body. **No** new `[arbiter] policy_applied` with a fresh `provider_used` from a new LLM success path for that attempt.  
- **`GET dispute-status`:** `arbiter_ai.limit_reached` = `true`, `arbiter_ai.invocations` equals cap.

---

## Operational notes

- Rotate keys in `.env` only; restart PHP / `php artisan config:clear` after changes.  
- **RU connectivity:** Production stack is **GigaChat only**; ensure OAuth + API endpoints are reachable from the server.  
- **GigaChat TLS:** On production, keep **`ARBITER_GIGACHAT_VERIFY_SSL` enabled** (default when `APP_ENV=production`) and install **Russian Trusted** root + sub CA on the API host — see *GigaChat TLS: Russian Trusted CA* above. Certbot does not fix outbound Sber TLS. Non-production defaults to **no verify** when unset (XAMPP / dev). Use `ARBITER_GIGACHAT_VERIFY_SSL=false` only as a last resort where the trust store cannot be updated.  
- Do not log raw prompts if they may contain PII; current info logs avoid message bodies beyond client-provided fields in structured keys—trim further if needed for compliance.
