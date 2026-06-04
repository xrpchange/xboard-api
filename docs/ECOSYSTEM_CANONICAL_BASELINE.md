# Ecosystem canonical baseline

**Tag (all repos):** `canonical-2026-06-04`

This marks the **most stable known point** across the XBoard multi-repo workspace (June 2026). If later work breaks something, **revert each repo to this tag** and debug from a known-good state.

The tag is on the default branch tip in every repository (`main` or `master`). There is **no separate long-lived branch** to merge — only the default branch plus this annotated tag.

## Repositories at this baseline

| Repository | Default branch | What is pinned |
|------------|----------------|----------------|
| **xboard-client** | `main` | Seller deal steps / IDLE; tokens filter; ecosystem baseline doc |
| **xboard-api** | `main` | AML `start_deal` buyer escrow type match; baseline doc |
| **xboard-core** | `master` | Stellar payment amount + XLM gas headroom; baseline doc |
| **xboard-monero-balance** | `main` | Stable service pin + baseline doc |
| **xboard-proxy-imap** | `main` | Stable proxy pin + baseline doc |

> **xboard-core** uses **`master`**, not `main`. All other listed repos use **`main`**.

**Verify commit** after checkout (must match on your machine):

```bash
git fetch origin --tags
git checkout canonical-2026-06-04
git rev-parse --short HEAD
```

The tag `canonical-2026-06-04` on `origin` points at the commit that includes `docs/ECOSYSTEM_CANONICAL_BASELINE.md` on the default branch.

## What was consolidated (2026-06-04)

- **xboard-client:** Removed merged stale branches (already contained in `main`):  
  `client/web-kotlin2-compose-coroutines-and-shell`, `fix/deal-seller-send-payment-details`, `web/webgl-idle-recovery-v70` (local + `origin`).
- **Other repos:** Only `main` / `master` — nothing to merge.
- **Remote:** Annotated tag `canonical-2026-06-04` pushed on GitHub for every repo above (same name, **different commit hash per repo**).

## Restore this baseline in one repo

```bash
git fetch origin --tags
git checkout canonical-2026-06-04
```

To move the default branch pointer to exactly this commit (destructive for uncommitted work):

```bash
# xboard-core: use master instead of main
git checkout main   # or: git checkout master
git reset --hard canonical-2026-06-04
```

## Restore the whole workspace

Run in **each** of the five project directories (adjust branch name for core):

```bash
git fetch origin --tags
git checkout main && git reset --hard canonical-2026-06-04    # client, api, monero-balance, proxy-imap
# in xboard-core:
git checkout master && git reset --hard canonical-2026-06-04
```

Redeploy servers from these commits after a hard reset (Core/API/web as you usually ship).

## Create a newer baseline later

When another stable point is agreed:

```bash
git tag -a canonical-YYYY-MM-DD -m "Ecosystem baseline checkpoint."
git push origin canonical-YYYY-MM-DD
# repeat on each repo; update this doc with the new table
```

Use a **new date** in the tag name; keep old tags for history.

## Related docs

- Client: `docs/petly/README.md`, `AGENTS.md`
- API: `docs/SPEC_REGISTRY.md`, `AGENTS.md`
- Core: `docs/CRYPTO_CLASSES_REFERENCE.md`, `AGENTS.md`
