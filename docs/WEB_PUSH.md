# Web Push (deal chat, browser background)

Browser **Web Push** complements **IMAP IDLE** (active tab). The API sends push after `insertDealChatMessage` — same trigger as deal mail, independent of whether the client’s WSS IDLE is alive.

## Server setup

```bash
php artisan web-push:generate-vapid
# Add WEB_PUSH_VAPID_PUBLIC_KEY / WEB_PUSH_VAPID_PRIVATE_KEY to .env
php artisan migrate
```

| Env | Purpose |
|-----|---------|
| `WEB_PUSH_VAPID_PUBLIC_KEY` | Exposed to web client for `PushManager.subscribe` |
| `WEB_PUSH_VAPID_PRIVATE_KEY` | Server-only |
| `WEB_PUSH_VAPID_SUBJECT` | `mailto:…` or `https://…` (default `mailto:admin@xdexboard.com`) |
| `WEB_PUSH_ENABLED` | `false` disables subscribe + send |

## API

| Method | Path | Auth |
|--------|------|------|
| GET | `/api/v1/web-push/vapid-public-key` | Public |
| POST | `/api/v1/web-push/subscribe` | `X-Escrow-Token` |
| POST | `/api/v1/web-push/unsubscribe` | `X-Escrow-Token` |

Subscribe body: `client_id`, `subscription` (Push API object), `watches[]` with `lot_id`, `node_key`, `role` (`buyer` \| `seller`).

Push payload (fixed English, no server locale): `title`, `body`, `lot_id`, `node_key`, `url` → `/open-deal?…`.

Recipient: opposite of `sender_side` on chat write (`buyer` → notify `seller` subscriptions, etc.).

## Client

- `webApp/src/main/resources/push-sw.js` — dedicated worker (not unregistered by boot cache purge).
- Settings → enable Web Push (web only); sync watches for active deals.
- See **xboard-client** `docs/WEB_PUSH.md`.

## Registry

| ID | Rule |
|----|------|
| WEB-PUSH-001 | VAPID configured → subscribe persists rows; chat insert queues push to matching lot+role |
| WEB-PUSH-002 | VAPID empty → subscribe 503, notifier no-op |
