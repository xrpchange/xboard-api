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

## Workflow for contributors

1. Describe behavior in the relevant `docs/*.md` file.
2. Add a row to this table with a new ID (next free in that area).
3. Add or update a PHPUnit test; tag with `@group spec` and `@group spec-<ID>`.
4. For behavior that needs full HTTP + DB, keep the test row empty in the **Test(s)** column until implemented, or reference a manual checklist step in **Notes**.
