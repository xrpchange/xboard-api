# Ecosystem canonical baseline

**Tag (all repos):** `canonical-2026-06-04`

This marks the **most stable known point** across the XBoard multi-repo workspace (June 2026). If later work breaks something, **revert each repo to this tag** and debug from a known-good state.

The tag is on the default branch tip in every repository (`main` or `master`). There is **no separate long-lived branch** to merge — only the default branch plus this annotated tag.

## Commits at this baseline

| Repository | Default branch | Commit | Notes |
|------------|----------------|--------|--------|
| **xboard-client** | `main` | `69a513a` | Seller deal steps / IDLE; tokens filter; this doc |
| **xboard-api** | `main` | `af8b19f` | AML `start_deal` buyer escrow type match; this doc |
| **xboard-core** | `master` | `dff9c2e` | Stellar amount + XLM gas headroom; this doc |
| **xboard-monero-balance** | `main` | `1c6b3d4` | Baseline pin + this doc |
| **xboard-proxy-imap** | `main` | `8cd5a98` | Baseline pin + this doc |

> **xboard-core** uses **`master`**, not `main`. All other listed repos use **`main`**.

## What was consolidated (2026-06-04)

- **xboard-client:** Removed merged stale branches (already contained in `main`):  
  `client/web-kotlin2-compose-coroutines-and-shell`, `fix/deal-seller-send-payment-details`, `web/webgl-idle-recovery-v70` (local + `origin`).
- **Other repos:** Only `main` / `master` — nothing to merge.
- **Remote:** Annotated tag `canonical-2026-06-04` on the commit in the table above (same as default-branch tip after this documentation push).

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
