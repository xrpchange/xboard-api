# Exchange lot search (GET /api/v1/exchange-lots)

Public buyer search returns active lots with pagination and configurable sort order.

## Environment

| Config key | `.env` | Default | Description |
|------------|--------|---------|-------------|
| `lot.search_page_size` | `LOT_SEARCH_PAGE_SIZE` | **50** | Default `per_page` when the client omits it. |
| `lot.search_page_size_max` | `LOT_SEARCH_PAGE_SIZE_MAX` | **100** | Maximum allowed `per_page` query value. |

## Query parameters

| Parameter | Required | Description |
|-----------|----------|-------------|
| `from_currency`, `to_currency`, … | As before | Search filters (unchanged). |
| `page` | No | Page number (Laravel pagination, default **1**). |
| `per_page` | No | Page size; defaults to `LOT_SEARCH_PAGE_SIZE`, capped at `LOT_SEARCH_PAGE_SIZE_MAX`. |
| `sort_by` | No | `updated_at` (default), `created_at`, or `completed_deals_count`. |
| `sort_dir` | No | `asc` or `desc` (default **desc**). |

### Sort presets (typical UI)

| User choice | `sort_by` | `sort_dir` |
|-------------|-----------|------------|
| Newest first (by last change) | `updated_at` | `desc` |
| Oldest first (by last change) | `updated_at` | `asc` |
| Newest created | `created_at` | `desc` |
| Oldest created | `created_at` | `asc` |
| Most completed deals | `completed_deals_count` | `desc` |

Invalid `sort_by` / `sort_dir` values fall back to `updated_at` + `desc`.

## Response

```json
{
  "success": true,
  "data": [ /* ExchangeLotResource items */ ],
  "meta": {
    "current_page": 1,
    "per_page": 50,
    "total": 123,
    "last_page": 3,
    "has_more": true,
    "sort_by": "updated_at",
    "sort_dir": "desc"
  }
}
```

Each lot includes `completed_deals_count`, `bad_deals_count`, `created_at`, and `updated_at` (UTC ISO-8601).

## Multi-node client

The Android/web client queries **each enabled exchanger** with the same filters, `page=1`, `sort_by`, and `sort_dir`.

### UI: blocks per exchanger

- Results are shown **per node** (section header = exchanger name).
- **Empty blocks are hidden** (no lots after API + client filters).
- **Several blocks** → collapsed by default; user expands each section.
- **Single exchanger with results** → flat lot list (no block header/card), same as before multi-node UI.
- **Sort** is chosen in the filter area **before** «Search»; last value is stored in app settings (`search_last_sort`, same as other search filters).
- **Pagination** is **per block**: «Load more» requests `page+1` only for that exchanger; sort order comes from the node API (no global merge sort).

See also: `docs/EXCHANGE_LOT_DEAL_COUNTERS.md`, xboard-client Search screen.

## Seller «my lots» (POST /api/v1/exchange-lots/seller-list)

**Protected** (`X-Escrow-Token` required). Lists lots owned by the seller’s escrow token(s).

| Field | Required | Description |
|-------|----------|-------------|
| `wallet_hashes` | **Yes** | JSON array of lowercase `token_hash` values (from escrow public part). |
| `include_inactive` | No | When `true`, include deactivated lots (seller UI). |
| `is_test`, `test_group`, `main_group` | No | Same as GET index (sandbox vs production groups). |
| `page`, `per_page`, `sort_by`, `sort_dir` | No | Same pagination/sort as public search. |

Response shape is identical to GET index. **Do not** pass full escrow JSON or `wallet_address[]` on GET — use this POST so filters stay in the body (no URI length limits).

Legacy GET `wallet_hashes` query (comma-separated) remains for old clients; new clients use POST only for seller my-lots.
