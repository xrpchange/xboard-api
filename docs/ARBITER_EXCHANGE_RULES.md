# Arbiter exchange rules digest (moved)

The canonical English digest loaded into the AI arbiter user prompt lives in the repository as:

**`resources/prompts/arbiter_exchange_rules.md`**

It is read at runtime by `App\Support\ArbiterPromptContextAssembler::loadRulesDigest()`.

## Where the rest is documented (do not duplicate long text here)

| Topic | Document |
|--------|----------|
| LLM provider (single primary, no vendor failover), env vars, verdict format, curl checklist | **`docs/AI_ARBITER_SERVER.md`** |
| `deal_disputes` lifecycle, dispute API routes | **`docs/DEAL_DISPUTE_SYSTEM.md`** |
| `deal_chat_messages`, chat endpoints, arbiter as chat-only writer | **`docs/DEAL_CHAT_TABLE_AND_SOFT_DELETE.md`** |
| Per-lot **AI invocation budget** (`exchange_lots.arbiter_ai_invocations`), `422` contract, `dispute-status` → `arbiter_ai` | **`docs/AI_ARBITER_SERVER.md`** → section *Per-lot AI invocation budget* |
| Config keys (`config/deal.php`) | **`config/deal.php`** (`max_ai_arbiter_invocations_per_lot`, `DEAL_MAX_AI_ARBITER_INVOCATIONS_PER_LOT`, legacy `DEAL_MAX_AI_ARBITER_MESSAGES_PER_LOT`) |
| DB migration for the counter column | **`database/migrations/2026_04_19_000001_add_arbiter_ai_invocations_to_exchange_lots.php`** |
| Android / Compose: errors, gateway, localization | Sibling repo **`xboard-client`**: `docs/DISPUTE_AI_ARBITER_CLIENT.md` |

Specification IDs: **`docs/SPEC_REGISTRY.md`** (`ARB-*`).
