# AI dispute arbiter (server-side)

**Scope:** `xboard-api` only. LLM API keys stay in `.env` (BYOK). Clients must not select or impersonate the provider.

### Specification vs implementation

Documented behavior is tracked in **[`docs/SPEC_REGISTRY.md`](SPEC_REGISTRY.md)** with stable IDs (`ARB-001`, …) linked to PHPUnit tests. After changing this file or arbiter code, update the registry and run:

```bash
php artisan test --group spec
```

---

## Security model

- **Provider selection and failover** are controlled only by server configuration (`config/services.php` + `.env`).  
- Request fields `provider` / `provider_hint` are **ignored for routing**; they may be logged and passed into the prompt as *non-authoritative* context (`provider_hint_applied` is always `false` in API responses).  
- **No API keys** are returned to clients.  
- Arbiter chat lines are **fixed English** technical/system copy where the server generates text (per project rules: no localized strings on the server).

---

## Architecture

| Piece | Role |
|--------|------|
| `App\Services\Arbiter\Contracts\ArbiterProvider` | Provider interface (`providerId()`, `generate()`). |
| `App\Services\Arbiter\GigaChatArbiterProvider` | Default primary: Sber GigaChat — OAuth token (cached ~30 min) + OpenAI-compatible `POST /chat/completions` under `api/v1`. |
| `App\Services\Arbiter\DeepSeekArbiterProvider` | Common RU fallback: OpenAI-compatible Chat Completions. |
| `App\Services\Arbiter\AnthropicArbiterProvider` | Optional: Anthropic Messages API (often needs VPN from RU). |
| `App\Services\Arbiter\OpenAiArbiterProvider` | Optional fallback: OpenAI Chat Completions API. |
| `App\Services\Arbiter\ArbiterService` | Rules-first orchestration + failover order. |
| `App\Support\ArbiterPromptContextAssembler` | Loads `resources/prompts/arbiter_exchange_rules.md`, `DealChatTagSequenceValidator::computeStateForLot`, and a requester-visible chat digest for the LLM user prompt. |
| `resources/prompts/arbiter_exchange_rules.md` | Canonical English digest for the model (not end-user UI copy). |
| `App\Providers\AppServiceProvider` | Wires providers and thresholds from config. |
| `ExchangeLotController::requestArbiterMessage` | Authz, validation, anti-replay check, merges arbiter prompt context, dispute row update, chat insert. |

---

## LLM context (exchange rules + chat)

Before calling the provider, the controller attaches (non-authoritative) context:

- **`exchange_rules_digest`** — trimmed contents of **`resources/prompts/arbiter_exchange_rules.md`** (fallback English line if file missing).  
- **`deal_machine_state`** — `DealChatTagSequenceValidator::computeStateForLot($lot)` (same reducer as `deal_tag` validation).  
- **`recent_chat_digest`** — last N messages visible to the **requester** (same `deleted_by_*` filters as `GET …/chat-messages`), one line per message with truncated `body` / `deal_tag`.

Enforcement of progression and payouts remains **server-side only**; the digest steers the model toward questions and conservative verdicts.

---

## Rules-first policy

1. **Deterministic context score** from message length, presence of evidence hashes, DKIM hash, and replay flag.  
2. **Anti-replay:** if any of `pdf_sha256` / `email_message_sha256` / `dkim_signature_sha256` matches a previous arbiter message’s `deal_tag.anti_replay.*` for the same lot → **manual review**, no LLM call (`reason_code`: `anti_replay_triggered`).  
3. **No-AI band:** if context score **≤ `ARBITER_NO_AI_BELOW`** → **manual review**, no LLM (`reason_code`: `insufficient_context`).  
4. **Gray zone:** otherwise the server calls the **primary** provider, then **fallback** providers on transport/API failure.  
5. **Post-LLM escalation:** if context score **&lt; `ARBITER_MANUAL_REVIEW_THRESHOLD`** but LLM ran, the response body includes the model text plus an English escalation suffix; `arbiter_status` is `manual_review` (`reason_code`: `low_confidence`).  
6. **All providers fail:** fixed English manual message (`reason_code`: `provider_unavailable`).

---

## Failover order

`ARBITER_PRIMARY_PROVIDER` first, then each id in `ARBITER_FALLBACK_PROVIDERS` (comma-separated).  
Unknown ids are skipped (logged at debug).  
Currently registered provider ids: `gigachat`, `deepseek`, `anthropic`, `openai`.

**Default stack (config):** primary `gigachat`, fallback `deepseek` — set `ARBITER_GIGACHAT_AUTHORIZATION_KEY` and ideally `ARBITER_DEEPSEEK_API_KEY` so failover works if GigaChat OAuth/API errors.

Example (RF-friendly):

```env
ARBITER_PRIMARY_PROVIDER=gigachat
ARBITER_GIGACHAT_AUTHORIZATION_KEY=<from Sber studio>
ARBITER_FALLBACK_PROVIDERS=deepseek
ARBITER_DEEPSEEK_API_KEY=sk-...
```

Example (EU/US host, Western APIs only):

```env
ARBITER_PRIMARY_PROVIDER=anthropic
ARBITER_FALLBACK_PROVIDERS=openai,deepseek
```

---

## Environment variables

| Variable | Default | Description |
|----------|---------|-------------|
| `ARBITER_PRIMARY_PROVIDER` | `gigachat` | First provider id to try. |
| `ARBITER_FALLBACK_PROVIDERS` | `deepseek` | Comma-separated fallback ids. |
| `ARBITER_NO_AI_BELOW` | `0.35` | Skip LLM when deterministic score is **≤** this value. |
| `ARBITER_MANUAL_REVIEW_THRESHOLD` | `0.55` | After LLM, mark `arbiter_status=manual_review` when score is below this. |
| `ARBITER_RESOLVE_ON_FAVOR_BUYER` | `true` | When LLM `verdict` is `favor_buyer` and `arbiter_status` is `in_review`, close `deal_disputes` so seller can use normal crypto payout (unless auto Core payout is on and fails). |
| `ARBITER_RESOLVE_ON_FAVOR_SELLER` | `false` | Same for `favor_seller`. |
| `ARBITER_AUTO_CORE_PAYOUT_ON_FAVOR_BUYER` | `false` | When `true`, after `favor_buyer` Laravel calls Core `POST /api/deal-payout-to-buyer-from-reservation` (seller token loaded from `reservations` / `test_reservations`; same `X-Core-Key` as other API-only Core calls). |
| `ARBITER_GIGACHAT_AUTHORIZATION_KEY` | *(empty)* | Sber **authorization key** (see studio quickstart): paste Base64 value only, or full `Basic …` / `Bearer …` header value. |
| `ARBITER_GIGACHAT_SCOPE` | `GIGACHAT_API_PERS` | OAuth scope (`GIGACHAT_API_B2B` / `GIGACHAT_API_CORP` for legal entities per Sber docs). |
| `ARBITER_GIGACHAT_OAUTH_URL` | `https://ngw.devices.sberbank.ru:9443/api/v2/oauth` | Token endpoint. |
| `ARBITER_GIGACHAT_API_BASE_URL` | `https://gigachat.devices.sberbank.ru/api/v1` | OpenAI-compatible API root. |
| `ARBITER_GIGACHAT_MODEL` | `GigaChat` | Model name. |
| `ARBITER_GIGACHAT_TIMEOUT` | `45` | HTTP timeout (seconds) for OAuth + chat. |
| `ARBITER_GIGACHAT_VERIFY_SSL` | `true` | Set `false` only if TLS to Sber endpoints fails on the host (diagnostics). |
| `ARBITER_DEEPSEEK_API_KEY` | *(empty)* | DeepSeek API key. |
| `ARBITER_DEEPSEEK_BASE_URL` | `https://api.deepseek.com` | API base (no trailing slash); override only for compatible proxies. |
| `ARBITER_DEEPSEEK_MODEL` | `deepseek-chat` | Model id (see DeepSeek docs). |
| `ARBITER_DEEPSEEK_TIMEOUT` | `30` | HTTP timeout (seconds). |
| `ARBITER_ANTHROPIC_API_KEY` | *(empty)* | Anthropic API key. |
| `ARBITER_ANTHROPIC_MODEL` | `claude-3-7-sonnet-latest` | Model id. |
| `ARBITER_ANTHROPIC_TIMEOUT` | `20` | HTTP timeout (seconds). |
| `ARBITER_OPENAI_API_KEY` | *(empty)* | OpenAI API key (fallback). |
| `ARBITER_OPENAI_MODEL` | `gpt-4o-mini` | Model id. |
| `ARBITER_OPENAI_TIMEOUT` | `20` | HTTP timeout (seconds). |

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

- **Setup:** `ARBITER_PRIMARY_PROVIDER=gigachat`, `ARBITER_GIGACHAT_AUTHORIZATION_KEY` set (or primary `deepseek` + `ARBITER_DEEPSEEK_API_KEY`); thresholds default; message length ≥ 40; include one evidence hash.  
- **Request:**

```bash
curl -sS -X POST "https://sandbox.api.xdexboard.com/api/v1/exchange-lots/{id}/arbiter-message" \
  -H "Content-Type: application/json" \
  -H "X-Escrow-Token: YOUR_TOKEN" \
  -d "{\"message\":\"Buyer states payment was sent with reference ABC123 and requests seller confirmation.\",\"pdf_sha256\":\"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\",\"bank_timezone\":\"UTC\",\"payment_time_raw\":\"2026-03-27 12:00:00\"}"
```

- **Expect:** HTTP 200, `success: true`, `data.dispute_status` = `in_review`, `data.arbiter_status` = `in_review`, `data.provider_used` = `gigachat` (or `deepseek` if that is primary), `data.body` = model text (English).  
- **Logs:** `[arbiter] policy_applied` with `arbiter_status=in_review`, matching `provider_used`.

### 2) Provider hint ignored

- **Request:** same as (1) but add `"provider":"openai"` or `"provider_hint":"fake"`.  
- **Expect:** `provider_used` matches server primary (`gigachat` by default) when the call succeeds; `provider_hint_applied` = `false`.  
- **Logs:** `provider_hint` present in log line, `provider_used` unchanged.

### 3) Failover chain

- **Setup A (GigaChat → DeepSeek):** invalid `ARBITER_GIGACHAT_AUTHORIZATION_KEY`; valid `ARBITER_DEEPSEEK_API_KEY`; `ARBITER_FALLBACK_PROVIDERS=deepseek`.  
- **Expect:** `provider_used` = `deepseek` after GigaChat OAuth or chat fails.  
- **Setup B:** any primary + valid fallback provider keys — same pattern.

### 4) No-AI path (insufficient context)

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

### 5) Anti-replay

- **Steps:** Run (1) with a fixed `email_message_sha256` (64 hex). Repeat the same hash on a second request.  
- **Expect:** second response `arbiter_status` = `manual_review`, `reason_code` = `anti_replay_triggered`, `deal_tag.anti_replay.triggered` = true on the new message.  
- **Logs:** `has_replay=true`.

### 6) All providers down

- **Setup:** wrong or empty keys for every provider in the primary + fallback list.  
- **Expect:** HTTP 200, `manual_review`, `reason_code` = `provider_unavailable`.  
- **Logs:** `[arbiter] provider_failed` for each, then `[arbiter] all_providers_failed`.

### 7) `dispute-status` still returns row

- After any successful arbiter call, `GET /api/v1/exchange-lots/{id}/dispute-status` should still return an active dispute with `status: in_review` (not `manual_review` at DB level).

---

## Operational notes

- Rotate keys in `.env` only; restart PHP / `php artisan config:clear` after changes.  
- **RU connectivity:** GigaChat and DeepSeek are typical defaults; Western APIs (Anthropic, OpenAI) often need VPN from RU. Tune `ARBITER_PRIMARY_PROVIDER` / fallbacks per host and compliance requirements.  
- **GigaChat TLS:** if OAuth or chat fails with certificate errors on the server, temporarily set `ARBITER_GIGACHAT_VERIFY_SSL=false` to confirm the diagnosis, then fix the host trust store or proxy.  
- Do not log raw prompts if they may contain PII; current info logs avoid message bodies beyond client-provided fields in structured keys—trim further if needed for compliance.
